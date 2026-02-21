<?php

class MovieService {
	public static function process($db, $rSettings, $rData) {
		return API::processMovieLegacy($rData);
	}

	public static function import($rData) {
		return API::importMoviesLegacy($rData);
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
			$rBouquets = getBouquets();
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
}
