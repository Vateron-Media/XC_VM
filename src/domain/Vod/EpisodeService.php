<?php

class EpisodeService {
	public static function process($db, $rSettings, $rData) {
		if (isset($rData['edit'])) {
			if (hasPermissions('adv', 'edit_episode')) {
				$rArray = overwriteData(getStream($rData['edit']), $rData);
			} else {
				exit();
			}
		} else {
			if (hasPermissions('adv', 'add_episode')) {
				$rArray = verifyPostTable('streams', $rData);
				$rArray['type'] = 5;
				$rArray['added'] = time();
				$rArray['series_no'] = intval($rData['series']);
				unset($rArray['id']);
			} else {
				exit();
			}
		}

		$rArray['stream_source'] = array($rData['stream_source']);

		if (0 < strlen($rData['movie_subtitles'])) {
			$rSplit = explode(':', $rData['movie_subtitles']);
			$rArray['movie_subtitles'] = array('files' => array($rSplit[2]), 'names' => array('Subtitles'), 'charset' => array('UTF-8'), 'location' => intval($rSplit[1]));
		} else {
			$rArray['movie_subtitles'] = null;
		}

		if (0 < $rArray['transcode_profile_id']) {
			$rArray['enable_transcode'] = 1;
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

		$rProcessArray = array();

		if (isset($rData['multi'])) {
			if (hasPermissions('adv', 'import_episodes')) {
				set_time_limit(0);
				include INCLUDES_PATH . 'libs/tmdb.php';
				$rSeries = getSerie(intval($rData['series']));

				if (0 < strlen($rSettings['tmdb_language'])) {
					$rTMDB = new TMDB($rSettings['tmdb_api_key'], $rSettings['tmdb_language']);
				} else {
					$rTMDB = new TMDB($rSettings['tmdb_api_key']);
				}

				$rJSON = json_decode($rTMDB->getSeason($rData['tmdb_id'], intval($rData['season_num']))->getJSON(), true);

				foreach ($rData as $rKey => $rFilename) {
					$rSplit = explode('_', $rKey);

					if ($rSplit[0] == 'episode' && $rSplit[2] == 'name') {
						if (0 < strlen($rData['episode_' . $rSplit[1] . '_num'])) {
							$rImportArray = array('filename' => '', 'properties' => array(), 'name' => '', 'episode' => 0, 'target_container' => '');
							$rEpisodeNum = intval($rData['episode_' . $rSplit[1] . '_num']);
							$rImportArray['filename'] = 's:' . $rData['server'] . ':' . $rData['season_folder'] . $rFilename;
							$rImage = '';

							if (isset($rData['addName1']) && isset($rData['addName2'])) {
								$rImportArray['name'] = $rSeries['title'] . ' - S' . sprintf('%02d', intval($rData['season_num'])) . 'E' . sprintf('%02d', $rEpisodeNum) . ' - ';
							} elseif (isset($rData['addName1'])) {
								$rImportArray['name'] = $rSeries['title'] . ' - ';
							} elseif (isset($rData['addName2'])) {
								$rImportArray['name'] = 'S' . sprintf('%02d', intval($rData['season_num'])) . 'E' . sprintf('%02d', $rEpisodeNum) . ' - ';
							}

							$rImportArray['episode'] = $rEpisodeNum;

							foreach ($rJSON['episodes'] as $rEpisode) {
								if (intval($rEpisode['episode_number']) == $rEpisodeNum) {
									if (0 < strlen($rEpisode['still_path'])) {
										$rImage = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rEpisode['still_path'];

										if ($rSettings['download_images']) {
											$rImage = CoreUtilities::downloadImage($rImage, 5);
										}
									}

									$rImportArray['name'] .= $rEpisode['name'];
									$rSeconds = intval($rSeries['episode_run_time']) * 60;
									$rImportArray['properties'] = array('tmdb_id' => $rEpisode['id'], 'release_date' => $rEpisode['air_date'], 'plot' => $rEpisode['overview'], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'movie_image' => $rImage, 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rEpisode['vote_average'], 'season' => $rData['season_num']);

									if (strlen($rImportArray['properties']['movie_image'][0]) == 0) {
										unset($rImportArray['properties']['movie_image']);
									}
								}
							}

							if (strlen($rImportArray['name']) == 0) {
								$rImportArray['name'] = 'No Episode Title';
							}

							$rPathInfo = pathinfo(explode('?', $rFilename)[0]);
							$rImportArray['target_container'] = $rPathInfo['extension'];
							$rProcessArray[] = $rImportArray;
						}
					}
				}
			} else {
				exit();
			}
		} else {
			$rImportArray = array('filename' => $rArray['stream_source'][0], 'properties' => array(), 'name' => $rArray['stream_display_name'], 'episode' => $rData['episode'], 'target_container' => $rData['target_container']);

			if ($rSettings['download_images']) {
				$rData['movie_image'] = CoreUtilities::downloadImage($rData['movie_image'], 5);
			}

			$rSeconds = intval($rData['episode_run_time']) * 60;
			$rImportArray['properties'] = array('release_date' => $rData['release_date'], 'plot' => $rData['plot'], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'movie_image' => $rData['movie_image'], 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rData['rating'], 'season' => $rData['season_num'], 'tmdb_id' => $rData['tmdb_id']);

			if (strlen($rImportArray['properties']['movie_image'][0]) == 0) {
				unset($rImportArray['properties']['movie_image']);
			}

			if ($rData['direct_proxy']) {
				$rExtension = pathinfo(explode('?', $rData['stream_source'])[0])['extension'];

				if ($rExtension) {
					$rImportArray['target_container'] = $rExtension;
				} elseif (!$rImportArray['target_container']) {
					$rImportArray['target_container'] = 'mp4';
				}
			}

			$rProcessArray[] = $rImportArray;
		}

		$rRestartIDs = array();

		foreach ($rProcessArray as $rImportArray) {
			$rArray['stream_source'] = array($rImportArray['filename']);
			$rArray['movie_properties'] = $rImportArray['properties'];
			$rArray['stream_display_name'] = $rImportArray['name'];

			if (!empty($rImportArray['target_container'])) {
				$rArray['target_container'] = $rImportArray['target_container'];
			} elseif (empty($rData['target_container'])) {
				$rArray['target_container'] = pathinfo(explode('?', $rImportArray['filename'])[0])['extension'];
			} else {
				$rArray['target_container'] = $rData['target_container'];
			}

			$rPrepare = prepareArray($rArray);
			$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = $db->last_insert_id();
				$db->query('DELETE FROM `streams_episodes` WHERE `stream_id` = ?;', $rInsertID);
				$db->query('INSERT INTO `streams_episodes`(`season_num`, `series_id`, `stream_id`, `episode_num`) VALUES(?, ?, ?, ?);', $rData['season_num'], $rData['series'], $rInsertID, $rImportArray['episode']);
				updateSeriesAsync(intval($rData['series']));
				$rStreamExists = array();

				if (isset($rData['edit'])) {
					$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);

					foreach ($db->get_rows() as $rRow) {
						$rStreamExists[intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
					}
				}

				$rStreamsAdded = array();
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
						deleteStream($rInsertID, $rServerID, true, false);
					}
				}

				if ($rRestart) {
					$rRestartIDs[] = $rInsertID;
				}

				$db->query('UPDATE `streams_series` SET `last_modified` = ? WHERE `id` = ?;', time(), $rData['streams_series']);
				CoreUtilities::updateStream($rInsertID);
			} else {
				return array('status' => STATUS_FAILURE);
			}
		}

		if ($rRestart) {
			APIRequest(array('action' => 'vod', 'sub' => 'start', 'stream_ids' => $rRestartIDs));
		}

		if (isset($rData['multi'])) {
			return array('status' => STATUS_SUCCESS_MULTI, 0 => array('series_id' => $rData['series']));
		}

		return array('status' => STATUS_SUCCESS, 'data' => array('series_id' => $rData['series'], 'insert_id' => $rInsertID));
	}

	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rEpisodes = json_decode($rData['episodes'], true);
		deleteStreams($rEpisodes, true);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massEdit($db, $rData) {
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

		$rStreamIDs = confirmIDs(json_decode($rData['streams'], true));

		if (0 < count($rStreamIDs)) {
			if (isset($rData['c_serie_name'])) {
				$db->query('UPDATE `streams_episodes` SET `series_id` = ? WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');', $rData['serie_name']);
				$db->query('UPDATE `streams` SET `series_no` = ? WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');', $rData['serie_name']);
			}

			$rPrepare = prepareArray($rArray);

			if (0 < count($rPrepare['data'])) {
				$rQuery = 'UPDATE `streams` SET ' . $rPrepare['update'] . ' WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');';
				$db->query($rQuery, ...$rPrepare['data']);
			}

			$rDeleteServers = $rQueueMovies = $rProcessServers = $rStreamExists = array();
			$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

			foreach ($db->get_rows() as $rRow) {
				$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
				$rProcessServers[intval($rRow['stream_id'])][] = intval($rRow['server_id']);
			}
			$rAddQuery = '';

			foreach ($rStreamIDs as $rStreamID) {
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

				if (isset($rData['reencode_on_edit'])) {
					foreach ($rProcessServers[$rStreamID] as $rServerID) {
						$rQueueMovies[$rServerID][] = $rStreamID;
					}
				}
			}

			foreach ($rDeleteServers as $rServerID => $rDeleteIDs) {
				deleteStreamsByServer($rDeleteIDs, $rServerID, true);
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
				CoreUtilities::refreshMovies($rStreamIDs, 3);
			}
		}

		return array('status' => STATUS_SUCCESS);
	}
}
