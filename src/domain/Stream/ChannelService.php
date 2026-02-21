<?php

class ChannelService {
	public static function process($rData, $db, $rSettings) {
		if (isset($rData['edit'])) {
			if (hasPermissions('adv', 'edit_cchannel')) {
				$rArray = overwriteData(getStream($rData['edit']), $rData);
			} else {
				exit();
			}
		} else {
			if (hasPermissions('adv', 'create_channel')) {
				$rArray = verifyPostTable('streams', $rData);
				$rArray['type'] = 3;
				$rArray['added'] = time();
				unset($rArray['id']);
			} else {
				exit();
			}
		}

		if (isset($rData['restart_on_edit'])) {
			$rRestart = true;
		} else {
			$rRestart = false;
		}

		if (isset($rData['reencode_on_edit'])) {
			$rReencode = true;
		} else {
			$rReencode = false;
		}

		foreach (array('allow_record', 'rtmp_output') as $rKey) {
			if (isset($rData[$rKey])) {
				$rArray[$rKey] = 1;
			} else {
				$rArray[$rKey] = 0;
			}
		}
		$rArray['movie_properties'] = array('type' => intval($rData['channel_type']));

		if (intval($rData['channel_type']) == 0) {
			$rPlaylist = generateSeriesPlaylist($rData['series_no']);
			$rArray['stream_source'] = $rPlaylist;
			$rArray['series_no'] = intval($rData['series_no']);
		} else {
			$rVideoFiles = $rData['video_files'];
			if (is_string($rVideoFiles)) {
				$rVideoFiles = json_decode($rVideoFiles, true);
			}
			$rArray['stream_source'] = is_array($rVideoFiles) ? $rVideoFiles : array();
			$rArray['series_no'] = 0;
		}

		if ($rData['transcode_profile_id'] == -1) {
			$rArray['movie_symlink'] = 1;
		} else {
			$rArray['movie_symlink'] = 0;
		}

		if (0 < count($rArray['stream_source'])) {
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
				$rPrepare = prepareArray(array('category_type' => 'live', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
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
					if (is_numeric($rCategory)) {
						$rCategories[] = intval($rCategory);
					}
				}
			}
			$rArray['category_id'] = '[' . implode(',', array_map('intval', $rCategories)) . ']';

			if (!$rSettings['download_images']) {
			} else {
				$rArray['stream_icon'] = CoreUtilities::downloadImage($rArray['stream_icon'], 3);
			}

			if (isset($rData['edit'])) {
			} else {
				$rArray['order'] = getNextOrder();
			}

			$rPrepare = prepareArray($rArray);
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

				$rStreamsAdded = array();
				$rServerTree = json_decode($rData['server_tree_data'], true);

				foreach ($rServerTree as $rServer) {
					if ($rServer['parent'] == '#') {
					} else {
						$rServerID = intval($rServer['id']);
						$rStreamsAdded[] = $rServerID;
						$rOD = intval(in_array($rServerID, ($rData['on_demand'] ?: array())));

						if ($rServer['parent'] == 'source') {
							$rParent = null;
						} else {
							$rParent = intval($rServer['parent']);
						}

						if (isset($rStreamExists[$rServerID])) {
							$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rServerID]);
						} else {
							$db->query("INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`, `pids_create_channel`, `cchannel_rsources`) VALUES(?, ?, ?, ?, '[]', '[]');", $rInsertID, $rServerID, $rParent, $rOD);
						}
					}
				}

				foreach ($rStreamExists as $rServerID => $rDBID) {
					if (in_array($rServerID, $rStreamsAdded)) {
					} else {
						deleteStream($rInsertID, $rServerID, false, false);
					}
				}

				if ($rReencode) {
					APIRequest(array('action' => 'stream', 'sub' => 'stop', 'stream_ids' => array($rInsertID)));
					$db->query("UPDATE `streams_servers` SET `pids_create_channel` = '[]', `cchannel_rsources` = '[]' WHERE `stream_id` = ?;", $rInsertID);
					CoreUtilities::queueChannel($rInsertID);
				}

				if ($rRestart) {
					APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => array($rInsertID)));
				}

				foreach ($rBouquets as $rBouquet) {
					addToBouquet('stream', $rBouquet, $rInsertID);
				}

				if (!isset($rData['edit'])) {
				} else {
					foreach (getBouquets() as $rBouquet) {
						if (in_array($rBouquet['id'], $rBouquets)) {
						} else {
							removeFromBouquet('stream', $rBouquet['id'], $rInsertID);
						}
					}
				}

				CoreUtilities::updateStream($rInsertID);

				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			} else {
				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_NO_SOURCES, 'data' => $rData);
		}
	}

	public static function massEdit($rData, $db) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rArray = array();

		foreach (array('allow_record', 'rtmp_output') as $rKey) {
			if (!isset($rData['c_' . $rKey])) {
			} else {
				if (isset($rData[$rKey])) {
					$rArray[$rKey] = 1;
				} else {
					$rArray[$rKey] = 0;
				}
			}
		}

		if (!isset($rData['c_transcode_profile_id'])) {
		} else {
			$rArray['transcode_profile_id'] = $rData['transcode_profile_id'];

			if (0 < $rArray['transcode_profile_id']) {
				$rArray['enable_transcode'] = 1;
			} else {
				$rArray['enable_transcode'] = 0;
			}
		}

		$rStreamIDs = json_decode($rData['streams'], true);

		if (0 >= count($rStreamIDs)) {
		} else {
			$rCategoryMap = array();

			if (!(isset($rData['c_category_id']) && in_array($rData['category_id_type'], array('ADD', 'DEL')))) {
			} else {
				$db->query('SELECT `id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: array());
				}
			}

			$rDeleteServers = $rProcessServers = $rStreamExists = array();
			$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

			foreach ($db->get_rows() as $rRow) {
				$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
				$rProcessServers[intval($rRow['stream_id'])][] = intval($rRow['server_id']);
			}
			$rBouquets = getBouquets();
			$rDelOptions = $rAddBouquet = $rDelBouquet = array();
			$rEncQuery = $rAddQuery = '';

			foreach ($rStreamIDs as $rStreamID) {
				if (!isset($rData['c_category_id'])) {
				} else {
					$rCategories = array_map('intval', $rData['category_id']);

					if ($rData['category_id_type'] == 'ADD') {
						foreach (($rCategoryMap[$rStreamID] ?: array()) as $rCategoryID) {
							if (in_array($rCategoryID, $rCategories)) {
							} else {
								$rCategories[] = $rCategoryID;
							}
						}
					} else {
						if ($rData['category_id_type'] != 'DEL') {
						} else {
							$rNewCategories = $rCategoryMap[$rStreamID];

							foreach ($rCategories as $rCategoryID) {
								if (($rKey = array_search($rCategoryID, $rNewCategories)) === false) {
								} else {
									unset($rNewCategories[$rKey]);
								}
							}
							$rCategories = $rNewCategories;
						}
					}

					$rArray['category_id'] = '[' . implode(',', $rCategories) . ']';
				}

				$rPrepare = prepareArray($rArray);

				if (0 >= count($rPrepare['data'])) {
				} else {
					$rPrepare['data'][] = $rStreamID;
					$rQuery = 'UPDATE `streams` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
					$db->query($rQuery, ...$rPrepare['data']);
				}

				if (!isset($rData['c_server_tree'])) {
				} else {
					$rStreamsAdded = array();
					$rServerTree = json_decode($rData['server_tree_data'], true);

					foreach ($rServerTree as $rServer) {
						if ($rServer['parent'] == '#') {
						} else {
							$rServerID = intval($rServer['id']);

							if (in_array($rData['server_type'], array('ADD', 'SET'))) {
								$rStreamsAdded[] = $rServerID;
								$rOD = intval(in_array($rServerID, ($rData['on_demand'] ?: array())));

								if ($rServer['parent'] == 'source') {
									$rParent = null;
								} else {
									$rParent = intval($rServer['parent']);
								}

								if (isset($rStreamExists[$rServerID])) {
									$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rServerID]);
								} else {
									$rAddQuery .= '(' . intval($rStreamID) . ', ' . intval($rServerID) . ', ' . (($rParent ?: 'NULL')) . ', ' . $rOD . '),';
								}

								$rProcessServers[$rStreamID][] = $rServerID;
							} else {
								if (!isset($rStreamExists[$rStreamID][$rServerID])) {
								} else {
									$rDeleteServers[$rServerID][] = $rStreamID;
								}
							}
						}
					}

					if ($rData['server_type'] != 'SET') {
					} else {
						foreach ($rStreamExists as $rServerID => $rDBID) {
							if (in_array($rServerID, $rStreamsAdded)) {
							} else {
								$rDeleteServers[$rServerID][] = $rStreamID;

								if (($rKey = array_search($rServerID, $rProcessServers[$rStreamID])) === false) {
								} else {
									unset($rProcessServers[$rStreamID][$rKey]);
								}
							}
						}
					}
				}

				if (!isset($rData['c_bouquets'])) {
				} else {
					if ($rData['bouquets_type'] == 'SET') {
						foreach ($rData['bouquets'] as $rBouquet) {
							$rAddBouquet[$rBouquet][] = $rStreamID;
						}

						foreach ($rBouquets as $rBouquet) {
							if (in_array($rBouquet['id'], $rData['bouquets'])) {
							} else {
								$rDelBouquet[$rBouquet['id']][] = $rStreamID;
							}
						}
					} else {
						if ($rData['bouquets_type'] == 'ADD') {
							foreach ($rData['bouquets'] as $rBouquet) {
								$rAddBouquet[$rBouquet][] = $rStreamID;
							}
						} else {
							if ($rData['bouquets_type'] != 'DEL') {
							} else {
								foreach ($rData['bouquets'] as $rBouquet) {
									$rDelBouquet[$rBouquet][] = $rStreamID;
								}
							}
						}
					}
				}

				if (!isset($rData['reencode_on_edit'])) {
				} else {
					foreach ($rProcessServers[$rStreamID] as $rServerID) {
						$rEncQuery .= "('channel', " . intval($rStreamID) . ', ' . intval($rServerID) . ', ' . time() . '),';
					}
				}
			}

			foreach ($rDeleteServers as $rServerID => $rDeleteIDs) {
				deleteStreamsByServer($rDeleteIDs, $rServerID, false);
			}

			foreach ($rAddBouquet as $rBouquetID => $rAddIDs) {
				addToBouquet('stream', $rBouquetID, $rAddIDs);
			}

			foreach ($rDelBouquet as $rBouquetID => $rRemIDs) {
				removeFromBouquet('stream', $rBouquetID, $rRemIDs);
			}

			if (empty($rAddQuery)) {
			} else {
				$rAddQuery = rtrim($rAddQuery, ',');
				$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES ' . $rAddQuery . ';');
			}

			CoreUtilities::updateStreams($rStreamIDs);

			if (isset($rData['reencode_on_edit'])) {
				$db->query("UPDATE `streams_servers` SET `pids_create_channel` = '[]', `cchannel_rsources` = '[]' WHERE `stream_id` IN (" . implode(',', array_map('intval', $rStreamIDs)) . ');');

				if (empty($rEncQuery)) {
				} else {
					$rEncQuery = rtrim($rEncQuery, ',');
					$db->query('INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES ' . $rEncQuery . ';');
				}

				APIRequest(array('action' => 'stream', 'sub' => 'stop', 'stream_ids' => array_values($rStreamIDs)));
			} else {
				if (!isset($rData['restart_on_edit'])) {
				} else {
					APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => array_values($rStreamIDs)));
				}
			}
		}

		return array('status' => STATUS_SUCCESS);
	}

	public static function setOrder($rData, $db) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);
		$rOrder = json_decode($rData['stream_order_array'], true);
		$rSort = 0;

		foreach ($rOrder as $rStream) {
			$db->query('UPDATE `streams` SET `order` = ? WHERE `id` = ?;', $rSort, $rStream);
			$rSort++;
		}

		return array('status' => STATUS_SUCCESS);
	}
}
