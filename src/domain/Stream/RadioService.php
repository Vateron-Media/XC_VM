<?php

/**
 * RadioService — radio service
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RadioService {
	public static function process($rData) {
		global $db;
		if (InputValidator::validate('processRadio', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_radio')) {
					$rArray = overwriteData(StreamRepository::getById($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_radio')) {
					$rArray = verifyPostTable('streams', $rData);
					$rArray['type'] = 4;
					$rArray['added'] = time();
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (isset($rData['days_to_restart']) && preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $rData['time_to_restart'])) {
				$rTimeArray = array('days' => array(), 'at' => $rData['time_to_restart']);

				foreach ($rData['days_to_restart'] as $rID => $rDay) {
					$rTimeArray['days'][] = $rDay;
				}
				$rArray['auto_restart'] = $rTimeArray;
			} else {
				$rArray['auto_restart'] = '';
			}

			if (isset($rData['direct_source'])) {
				$rArray['direct_source'] = 1;
			} else {
				$rArray['direct_source'] = 0;
			}

			if (isset($rData['probesize_ondemand'])) {
				$rArray['probesize_ondemand'] = intval($rData['probesize_ondemand']);
			} else {
				$rArray['probesize_ondemand'] = 128000;
			}

			if (isset($rData['restart_on_edit'])) {
				$rRestart = true;
			} else {
				$rRestart = false;
			}

			$rImportStreams = array();

			if (0 < strlen($rData['stream_source'][0])) {
				$rImportArray = array('stream_source' => $rData['stream_source'], 'stream_icon' => $rArray['stream_icon'], 'stream_display_name' => $rArray['stream_display_name']);
				$rImportStreams[] = $rImportArray;

				if (0 < count($rImportStreams)) {
					$rBouquetCreate = array();

					foreach (json_decode($rData['bouquet_create_list'] ?? '[]', true) ?: [] as $rBouquet) {
						$rPrepare = prepareArray(array('bouquet_name' => $rBouquet, 'bouquet_channels' => array(), 'bouquet_movies' => array(), 'bouquet_series' => array(), 'bouquet_radios' => array()));
						$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (!$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rBouquetID = $db->last_insert_id();
							$rBouquetCreate[$rBouquet] = $rBouquetID;
						}
					}
					$rCategoryCreate = array();

					foreach (json_decode($rData['category_create_list'] ?? '[]', true) ?: [] as $rCategory) {
						$rPrepare = prepareArray(array('category_type' => 'radio', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
						$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (!$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rCategoryID = $db->last_insert_id();
							$rCategoryCreate[$rCategory] = $rCategoryID;
						}
					}

					foreach ($rImportStreams as $rImportStream) {
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

						foreach ($rData['category_id'] ?? [] as $rCategory) {
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
						$rImportArray = $rArray;

						if (!SettingsManager::getAll()['download_images']) {
						} else {
							$rImportStream['stream_icon'] = ImageUtils::downloadImage($rImportStream['stream_icon'], 4);
						}

						foreach (array_keys($rImportStream) as $rKey) {
							$rImportArray[$rKey] = $rImportStream[$rKey];
						}

						if (isset($rData['edit'])) {
						} else {
							$rImportArray['order'] = StreamRepository::getNextOrder();
						}

						$rPrepare = prepareArray($rImportArray);
						$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if ($db->query($rQuery, ...$rPrepare['data'])) {
							$rInsertID = $db->last_insert_id();
							$rStationExists = array();

							if (!isset($rData['edit'])) {
							} else {
								$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);

								foreach ($db->get_rows() as $rRow) {
									$rStationExists[intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
								}
							}

							$rStreamsAdded = array();
							$rServerTree = json_decode($rData['server_tree_data'], true);

							foreach ($rServerTree as $rServer) {
								if ($rServer['parent'] == '#') {
								} else {
									$rServerID = intval($rServer['id']);
									$rStreamsAdded[] = $rServerID;
									$rOD = intval(in_array($rServerID, ($rData['on_demand'] ?? [])));

									if ($rServer['parent'] == 'source') {
										$rParent = null;
									} else {
										$rParent = intval($rServer['parent']);
									}

									if (isset($rStationExists[$rServerID])) {
										$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStationExists[$rServerID]);
									} else {
										$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES(?, ?, ?, ?);', $rInsertID, $rServerID, $rParent, $rOD);
									}
								}
							}

							foreach ($rStationExists as $rServerID => $rDBID) {
								if (in_array($rServerID, $rStreamsAdded)) {
								} else {
									deleteStream($rInsertID, $rServerID, false, false);
								}
							}
							$db->query('DELETE FROM `streams_options` WHERE `stream_id` = ?;', $rInsertID);

							if (!(isset($rData['user_agent']) && 0 < strlen($rData['user_agent']))) {
							} else {
								$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 1, ?);', $rInsertID, $rData['user_agent']);
							}

							if (!(isset($rData['http_proxy']) && 0 < strlen($rData['http_proxy']))) {
							} else {
								$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 2, ?);', $rInsertID, $rData['http_proxy']);
							}

							if (!(isset($rData['cookie']) && 0 < strlen($rData['cookie']))) {
							} else {
								$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 17, ?);', $rInsertID, $rData['cookie']);
							}

							if (!(isset($rData['headers']) && 0 < strlen($rData['headers']))) {
							} else {
								$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 19, ?);', $rInsertID, $rData['headers']);
							}

							if (isset($rData['skip_ffprobe']) && ($rData['skip_ffprobe'] == 'on' || $rData['skip_ffprobe'] == 1)) {
								$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 21, ?);', $rInsertID, '1');
							}

							if (isset($rData['force_input_acodec']) && strlen(trim($rData['force_input_acodec'])) > 0) {
								$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 20, ?);', $rInsertID, trim($rData['force_input_acodec']));
							}

							if (!$rRestart) {
							} else {
								APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => array($rInsertID)));
							}

							foreach ($rBouquets as $rBouquet) {
								addToBouquet('radio', $rBouquet, $rInsertID);
							}

							if (!isset($rData['edit'])) {
							} else {
								foreach (BouquetService::getAllSimple() as $rBouquet) {
									if (in_array($rBouquet['id'], $rBouquets)) {
									} else {
										removeFromBouquet('radio', $rBouquet['id'], $rInsertID);
									}
								}
							}

							StreamProcess::updateStream($rInsertID);

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
					}
				} else {
					return array('status' => STATUS_NO_SOURCES, 'data' => $rData);
				}
			} else {
				return array('status' => STATUS_NO_SOURCES, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function massEdit($rData) {
		global $db;
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (InputValidator::validate('massEditRadios', $rData)) {
			$rArray = array();

			if (!isset($rData['c_direct_source'])) {
			} else {
				if (isset($rData['direct_source'])) {
					$rArray['direct_source'] = 1;
				} else {
					$rArray['direct_source'] = 0;
				}
			}

			if (!isset($rData['c_custom_sid'])) {
			} else {
				$rArray['custom_sid'] = $rData['custom_sid'];
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

				$rDeleteServers = $rStreamExists = array();
				$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
				}
				$rBouquets = BouquetService::getAllSimple();
				$rAddBouquet = $rDelBouquet = array();
				$rAddQuery = '';

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
						$rODTree = json_decode($rData['od_tree_data'], true);

						foreach ($rServerTree as $rServer) {
							if ($rServer['parent'] == '#') {
							} else {
								$rServerID = intval($rServer['id']);

								if (in_array($rData['server_type'], array('ADD', 'SET'))) {
									$rOD = intval(in_array($rServerID, ($rData['on_demand'] ?: array())));

									if ($rServer['parent'] == 'source') {
										$rParent = null;
									} else {
										$rParent = intval($rServer['parent']);
									}

									$rStreamsAdded[] = $rServerID;

									if (isset($rStreamExists[$rStreamID][$rServerID])) {
										$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rStreamID][$rServerID]);
									} else {
										$rAddQuery .= '(' . intval($rStreamID) . ', ' . intval($rServerID) . ', ' . (($rParent ?: 'NULL')) . ', ' . $rOD . '),';
									}
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
							foreach ($rStreamExists[$rStreamID] as $rServerID => $rDBID) {
								if (in_array($rServerID, $rStreamsAdded)) {
								} else {
									$rDeleteServers[$rServerID][] = $rStreamID;
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
				}

				foreach ($rDeleteServers as $rServerID => $rDeleteIDs) {
					deleteStreamsByServer($rDeleteIDs, $rServerID, false);
				}

				foreach ($rAddBouquet as $rBouquetID => $rAddIDs) {
					addToBouquet('radio', $rBouquetID, $rAddIDs);
				}

				foreach ($rDelBouquet as $rBouquetID => $rRemIDs) {
					removeFromBouquet('radio', $rBouquetID, $rRemIDs);
				}

				if (empty($rAddQuery)) {
				} else {
					$rAddQuery = rtrim($rAddQuery, ',');
					$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES ' . $rAddQuery . ';');
				}

				StreamProcess::updateStreams($rStreamIDs);

				if (!isset($rData['restart_on_edit'])) {
				} else {
					APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => array_values($rStreamIDs)));
				}
			}

			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (InputValidator::validate('massDeleteStations', $rData)) {
			$rStreams = json_decode($rData['radios'], true);
			deleteStreams($rStreams, false);
			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}
}
