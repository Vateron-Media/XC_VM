<?php

class MovieService {
	public static function process($rSettings, $rData) {
		global $db;
		if (InputValidator::validate('processMovie', $rData)) {
			set_time_limit(0);
			ini_set('mysql.connect_timeout', 0);
			ini_set('max_execution_time', 0);
			ini_set('default_socket_timeout', 0);

			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_movie')) {
					$rArray = overwriteData(StreamRepository::getById($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_movie')) {
					$rArray = verifyPostTable('streams', $rData);
					$rArray['added'] = time();
					$rArray['type'] = 2;
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (0 < strlen($rData['movie_subtitles'])) {
				$rSplit = explode(':', $rData['movie_subtitles']);
				$rArray['movie_subtitles'] = array('files' => array($rSplit[2]), 'names' => array('Subtitles'), 'charset' => array('UTF-8'), 'location' => intval($rSplit[1]));
			} else {
				$rArray['movie_subtitles'] = null;
			}

			if (0 >= $rArray['transcode_profile_id']) {
			} else {
				$rArray['enable_transcode'] = 1;
			}

			if (!(!is_numeric($rArray['year']) || $rArray['year'] < 1900 || intval(date('Y') + 1) < $rArray['year'])) {
			} else {
				$rArray['year'] = null;
			}

			foreach (array('read_native', 'movie_symlink', 'direct_source', 'direct_proxy', 'remove_subtitles') as $rKey) {
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
			$rImportStreams = array();

			if (isset($rData['review'])) {
				require_once MAIN_HOME . 'includes/libs/tmdb.php';

				if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
					$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
				} else {
					$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
				}

				$rReview = true;

				foreach ($rData['review'] as $rImportStream) {
					if (!$rImportStream['tmdb_id']) {
					} else {
						$rMovie = $rTMDB->getMovie($rImportStream['tmdb_id']);

						if (!$rMovie) {
						} else {
							$rMovieData = json_decode($rMovie->getJSON(), true);
							$rMovieData['trailer'] = $rMovie->getTrailer();
							$rThumb = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rMovieData['poster_path'];
							$rBG = 'https://image.tmdb.org/t/p/w1280' . $rMovieData['backdrop_path'];

							if (!CoreUtilities::$rSettings['download_images']) {
							} else {
								$rThumb = CoreUtilities::downloadImage($rThumb, 2);
								$rBG = CoreUtilities::downloadImage($rBG);
							}

							$rCast = array();

							foreach ($rMovieData['credits']['cast'] as $rMember) {
								if (count($rCast) >= 5) {
								} else {
									$rCast[] = $rMember['name'];
								}
							}
							$rDirectors = array();

							foreach ($rMovieData['credits']['crew'] as $rMember) {
								if (!(count($rDirectors) < 5 && ($rMember['department'] == 'Directing' || $rMember['known_for_department'] == 'Directing'))) {
								} else {
									$rDirectors[] = $rMember['name'];
								}
							}
							$rCountry = '';

							if (!isset($rMovieData['production_countries'][0]['name'])) {
							} else {
								$rCountry = $rMovieData['production_countries'][0]['name'];
							}

							$rGenres = array();

							foreach ($rMovieData['genres'] as $rGenre) {
								if (count($rGenres) >= 3) {
								} else {
									$rGenres[] = $rGenre['name'];
								}
							}
							$rSeconds = intval($rMovieData['runtime']) * 60;

							if (0 < strlen($rMovieData['release_date'])) {
								$rYear = intval(substr($rMovieData['release_date'], 0, 4));
							} else {
								$rYear = null;
							}

							$rImportStream['movie_properties'] = array('kinopoisk_url' => 'https://www.themoviedb.org/movie/' . $rMovieData['id'], 'tmdb_id' => $rMovieData['id'], 'name' => $rMovieData['title'], 'year' => $rYear, 'o_name' => $rMovieData['original_title'], 'cover_big' => $rThumb, 'movie_image' => $rThumb, 'release_date' => $rMovieData['release_date'], 'episode_run_time' => $rMovieData['runtime'], 'youtube_trailer' => $rMovieData['trailer'], 'director' => implode(', ', $rDirectors), 'actors' => implode(', ', $rCast), 'cast' => implode(', ', $rCast), 'description' => $rMovieData['overview'], 'plot' => $rMovieData['overview'], 'age' => '', 'mpaa_rating' => '', 'rating_count_kinopoisk' => 0, 'country' => $rCountry, 'genre' => implode(', ', $rGenres), 'backdrop_path' => array($rBG), 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rMovieData['vote_average']);
						}
					}

					unset($rImportStream['tmdb_id']);
					$rImportStream['async'] = false;
					$rImportStream['target_container'] = pathinfo(explode('?', $rImportStream['stream_source'][0])[0])['extension'];

					if (!empty($rImportStream['target_container'])) {
					} else {
						$rImportStream['target_container'] = 'mp4';
					}

					$rImportStreams[] = $rImportStream;
				}
			} else {
				$rImportStreams = array();

				if (!empty($_FILES['m3u_file']['tmp_name'])) {
					if (Authorization::check('adv', 'import_movies')) {
						$rStreamDatabase = array();

						$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 2;');

						foreach ($db->get_rows() as $rRow) {
							foreach (json_decode($rRow['stream_source'], true) as $rSource) {
								if (0 >= strlen($rSource)) {
								} else {
									$rStreamDatabase[] = $rSource;
								}
							}
						}
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
							if (in_array($rResult['url'], $rStreamDatabase)) {
							} else {
								$rPathInfo = pathinfo(explode('?', $rResult['url'])[0]);
								$rImportArray = array('stream_source' => array($rResult['url']), 'stream_icon' => ($rResult['tvg-logo'] ?: ''), 'stream_display_name' => ($rResult['name'] ?: ''), 'movie_properties' => array(), 'async' => true, 'target_container' => $rPathInfo['extension']);
								$rImportStreams[] = $rImportArray;
							}
						}
					} else {
						exit();
					}
				} else {
					if (!empty($rData['import_folder'])) {
						if (Authorization::check('adv', 'import_movies')) {
							$rStreamDatabase = array();

							$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 2;');

							foreach ($db->get_rows() as $rRow) {
								foreach (json_decode($rRow['stream_source'], true) as $rSource) {
									if (0 >= strlen($rSource)) {
									} else {
										$rStreamDatabase[] = $rSource;
									}
								}
							}
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

									if (in_array($rFilePath, $rStreamDatabase)) {
									} else {
										$rPathInfo = pathinfo($rFile);
										$rImportArray = array('stream_source' => array($rFilePath), 'stream_icon' => '', 'stream_display_name' => $rPathInfo['filename'], 'movie_properties' => array(), 'async' => true, 'target_container' => $rPathInfo['extension']);
										$rImportStreams[] = $rImportArray;
									}
								}
							}
						} else {
							exit();
						}
					} else {
						$rImportArray = array('stream_source' => array($rData['stream_source']), 'stream_icon' => $rArray['stream_icon'], 'stream_display_name' => $rArray['stream_display_name'], 'movie_properties' => array(), 'async' => false, 'target_container' => $rArray['target_container']);

						if (0 < strlen($rData['tmdb_id'])) {
							$rTMDBURL = 'https://www.themoviedb.org/movie/' . $rData['tmdb_id'];
						} else {
							$rTMDBURL = '';
						}

						if (!CoreUtilities::$rSettings['download_images']) {
						} else {
							$rData['movie_image'] = CoreUtilities::downloadImage($rData['movie_image'], 2);
							$rData['backdrop_path'] = CoreUtilities::downloadImage($rData['backdrop_path']);
						}

						$rSeconds = intval($rData['episode_run_time']) * 60;
						$rImportArray['movie_properties'] = array('kinopoisk_url' => $rTMDBURL, 'tmdb_id' => $rData['tmdb_id'], 'name' => $rArray['stream_display_name'], 'o_name' => $rArray['stream_display_name'], 'cover_big' => $rData['movie_image'], 'movie_image' => $rData['movie_image'], 'release_date' => $rData['release_date'], 'episode_run_time' => $rData['episode_run_time'], 'youtube_trailer' => $rData['youtube_trailer'], 'director' => $rData['director'], 'actors' => $rData['cast'], 'cast' => $rData['cast'], 'description' => $rData['plot'], 'plot' => $rData['plot'], 'age' => '', 'mpaa_rating' => '', 'rating_count_kinopoisk' => 0, 'country' => $rData['country'], 'genre' => $rData['genre'], 'backdrop_path' => array($rData['backdrop_path']), 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rData['rating']);

						if (strlen($rImportArray['movie_properties']['backdrop_path'][0]) != 0) {
						} else {
							unset($rImportArray['movie_properties']['backdrop_path']);
						}

						if ($rData['movie_symlink'] || $rData['direct_proxy']) {
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
				$rBouquetCreate = array();
				$rCategoryCreate = array();

				if ($rReview) {
				} else {
					foreach (json_decode($rData['bouquet_create_list'], true) as $rBouquet) {
						$rPrepare = prepareArray(array('bouquet_name' => $rBouquet, 'bouquet_channels' => array(), 'bouquet_movies' => array(), 'bouquet_series' => array(), 'bouquet_radios' => array()));
						$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (!$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rBouquetID = $db->last_insert_id();
							$rBouquetCreate[$rBouquet] = $rBouquetID;
						}
					}

					foreach (json_decode($rData['category_create_list'], true) as $rCategory) {
						$rPrepare = prepareArray(array('category_type' => 'movie', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
						$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (!$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rCategoryID = $db->last_insert_id();
							$rCategoryCreate[$rCategory] = $rCategoryID;
						}
					}
				}

				$rRestartIDs = array();

				foreach ($rImportStreams as $rImportStream) {
					$rImportArray = $rArray;

					if ($rReview) {
						$rImportArray['category_id'] = '[' . implode(',', array_map('intval', $rImportStream['category_id'])) . ']';
						$rBouquets = array_map('intval', $rImportStream['bouquets']);
						unset($rImportStream['bouquets']);
					} else {
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
						$rImportArray['category_id'] = '[' . implode(',', array_map('intval', $rCategories)) . ']';
					}

					if (!isset($rImportArray['movie_properties']['rating'])) {
					} else {
						$rImportArray['rating'] = $rImportArray['movie_properties']['rating'];
					}

					foreach (array_keys($rImportStream) as $rKey) {
						$rImportArray[$rKey] = $rImportStream[$rKey];
					}

					if (isset($rData['edit'])) {
					} else {
						$rImportArray['order'] = StreamRepository::getNextOrder();
					}

					$rImportArray['tmdb_id'] = ($rImportStream['movie_properties']['tmdb_id'] ?: null);
					$rSync = $rImportArray['async'];
					unset($rImportArray['async']);
					$rPrepare = prepareArray($rImportArray);
					$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if ($db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = $db->last_insert_id();
						$rStreamExists = array();

						if (!isset($rData['edit'])) {
						} else {
							$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);

							foreach ($db->get_rows() as $rRow) {
								$rStreamExists[intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
							}
						}

						$rPath = $rImportArray['stream_source'][0];

						if (substr($rPath, 0, 2) != 's:') {
						} else {
							$rSplit = explode(':', $rPath, 3);
							$rPath = $rSplit[2];
						}

						$db->query('UPDATE `watch_logs` SET `status` = 1, `stream_id` = ? WHERE `filename` = ? AND `type` = 1;', $rInsertID, $rPath);
						$rStreamsAdded = array();
						$rServerTree = json_decode($rData['server_tree_data'], true);

						foreach ($rServerTree as $rServer) {
							if ($rServer['parent'] == '#') {
							} else {
								$rServerID = intval($rServer['id']);
								$rStreamsAdded[] = $rServerID;

								if (isset($rStreamExists[$rServerID])) {
								} else {
									$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `on_demand`) VALUES(?, ?, 0);', $rInsertID, $rServerID);
								}
							}
						}

						foreach ($rStreamExists as $rServerID => $rDBID) {
							if (in_array($rServerID, $rStreamsAdded)) {
							} else {
								deleteStream($rInsertID, $rServerID, true, false);
							}
						}

						if ($rRestart) {
							$rRestartIDs[] = $rInsertID;
						}

						foreach ($rBouquets as $rBouquet) {
							addToBouquet('movie', $rBouquet, $rInsertID);
						}

						foreach (BouquetService::getAllSimple() as $rBouquet) {
							if (in_array($rBouquet['id'], $rBouquets)) {
							} else {
								removeFromBouquet('movie', $rBouquet['id'], $rInsertID);
							}
						}

						if (!$rSync) {
						} else {
							$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(1, ?, 0);', $rInsertID);
						}

						CoreUtilities::updateStream($rInsertID);
					} else {
						foreach ($rBouquetCreate as $rBouquet => $rID) {
							$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
						}

						foreach ($rCategoryCreate as $rCategory => $rID) {
							$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
						}

						return array('status' => STATUS_FAILURE, 'data' => $rData);
					}
				}

				if (!$rRestart) {
				} else {
					APIRequest(array('action' => 'vod', 'sub' => 'start', 'stream_ids' => $rRestartIDs));
				}

				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			} else {
				return array('status' => STATUS_NO_SOURCES, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function import($rData) {
		global $db;
		if (Authorization::check('adv', 'import_movies')) {
			if (InputValidator::validate('importMovies', $rData)) {
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

				$rStreamDatabase = array();
				$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 2;');

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

				$rMovieCategories = array_keys(getCategories('movie'));

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
						$rPrepare = prepareArray(array('category_type' => 'movie', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
						$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (!$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rCategories[] = $db->last_insert_id();
						}
					}

					foreach ($rData['category_id'] as $rCategoryID) {
						if (!(is_numeric($rCategoryID) && in_array($rCategoryID, $rMovieCategories))) {
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
						$rData = array('import' => true, 'type' => 'movie', 'title' => $rImportStream['title'], 'file' => $rImportStream['url'], 'subtitles' => array(), 'servers' => $rServerIDs, 'fb_category_id' => $rCategories, 'fb_bouquets' => $rBouquets, 'disable_tmdb' => $rDisableTMDB, 'ignore_no_match' => $rIgnoreMatch, 'bouquets' => array(), 'category_id' => array(), 'language' => CoreUtilities::$rSettings['tmdb_language'], 'watch_categories' => $rWatchCategories, 'read_native' => $rData['read_native'], 'movie_symlink' => $rData['movie_symlink'], 'remove_subtitles' => $rData['remove_subtitles'], 'direct_source' => $rData['direct_source'], 'direct_proxy' => $rData['direct_proxy'], 'auto_encode' => $rRestart, 'auto_upgrade' => false, 'fallback_title' => false, 'ffprobe_input' => false, 'transcode_profile_id' => $rData['transcode_profile_id'], 'target_container' => $rImportStream['container'], 'max_genres' => intval(CoreUtilities::$rSettings['max_genres']), 'duplicate_tmdb' => true);
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

		$rMovies = json_decode($rData['movies'], true);
		deleteStreams($rMovies, true);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massEdit($rData) {
		global $db;
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rArray = array();

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

		$rStreamIDs = json_decode($rData['streams'], true);

		if (0 < count($rStreamIDs)) {
			$rCategoryMap = array();

			if (isset($rData['c_category_id']) && in_array($rData['category_id_type'], array('ADD', 'DEL'))) {
				$db->query('SELECT `id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: array());
				}
			}

			$rDeleteServers = $rQueueMovies = $rProcessServers = $rStreamExists = array();
			$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

			foreach ($db->get_rows() as $rRow) {
				$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
				$rProcessServers[intval($rRow['stream_id'])][] = intval($rRow['server_id']);
			}
			$rBouquets = BouquetService::getAllSimple();
			$rAddBouquet = $rDelBouquet = array();
			$rAddQuery = '';

			foreach ($rStreamIDs as $rStreamID) {
				if (isset($rData['c_category_id'])) {
					$rCategories = array_map('intval', $rData['category_id']);

					if ($rData['category_id_type'] == 'ADD') {
						foreach (($rCategoryMap[$rStreamID] ?: array()) as $rCategoryID) {
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

				$rPrepare = prepareArray($rArray);

				if (0 < count($rPrepare['data'])) {
					$rPrepare['data'][] = $rStreamID;
					$rQuery = 'UPDATE `streams` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
					$db->query($rQuery, ...$rPrepare['data']);
				}

				if (isset($rData['c_server_tree'])) {
					$rStreamsAdded = array();
					$rServerTree = json_decode($rData['server_tree_data'], true);

					foreach ($rServerTree as $rServer) {
						if ($rServer['parent'] != '#') {
							$rServerID = intval($rServer['id']);

							if (in_array($rData['server_type'], array('ADD', 'SET'))) {
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
				deleteStreamsByServer($rDeleteIDs, $rServerID, true);
			}

			foreach ($rAddBouquet as $rBouquetID => $rAddIDs) {
				addToBouquet('movie', $rBouquetID, $rAddIDs);
			}

			foreach ($rDelBouquet as $rBouquetID => $rRemIDs) {
				removeFromBouquet('movie', $rBouquetID, $rRemIDs);
			}

			if (!empty($rAddQuery)) {
				$rAddQuery = rtrim($rAddQuery, ',');
				$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`) VALUES ' . $rAddQuery . ';');
			}

			CoreUtilities::updateStreams($rStreamIDs);

			if (isset($rData['reencode_on_edit'])) {
				foreach ($rQueueMovies as $rServerID => $rQueueIDs) {
					CoreUtilities::queueMovies($rQueueIDs, $rServerID);
				}
			}

			if (isset($rData['reprocess_tmdb'])) {
				CoreUtilities::refreshMovies($rStreamIDs, 1);
			}
		}

		return array('status' => STATUS_SUCCESS);
	}

	// ──────────── Из MovieRepository ────────────

	public static function getSimilar($rID, $rPage = 1) {
		require_once MAIN_HOME . 'includes/libs/tmdb.php';

		if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
			$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
		} else {
			$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
		}

		return json_decode(json_encode($rTMDB->getSimilarMovies($rID, $rPage)), true);
	}

	/**
	 * Send delete signal for movie files on specified servers.
	 */
	public static function deleteFile($rServerIDs, $rID) {
		global $db;
		if (is_array($rServerIDs)) {
		} else {
			$rServerIDs = array($rServerIDs);
		}

		foreach ($rServerIDs as $rServerID) {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', $rServerID, time(), json_encode(array('type' => 'delete_vod', 'id' => $rID)));
		}

		return true;
	}
}
