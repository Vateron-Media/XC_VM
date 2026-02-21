<?php

class StreamService {
	public static function process($rData, $db, $rSettings) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (isset($rData['edit'])) {
			if (hasPermissions('adv', 'edit_stream')) {
				$rArray = overwriteData(getStream($rData['edit']), $rData);
			} else {
				exit();
			}
		} else {
			if (hasPermissions('adv', 'add_stream')) {
				$rArray = verifyPostTable('streams', $rData);
				$rArray['type'] = 1;
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

		foreach (array('fps_restart', 'gen_timestamps', 'allow_record', 'rtmp_output', 'stream_all', 'direct_source', 'direct_proxy', 'read_native') as $rKey) {
			if (isset($rData[$rKey])) {
				$rArray[$rKey] = 1;
			} else {
				$rArray[$rKey] = 0;
			}
		}

		if (!$rArray['transcode_profile_id']) {
			$rArray['transcode_profile_id'] = 0;
		}

		if ($rArray['transcode_profile_id'] > 0) {
			$rArray['enable_transcode'] = 1;
		}

		if (isset($rData['restart_on_edit'])) {
			$rRestart = true;
		} else {
			$rRestart = false;
		}

		$rReview = false;
		$rImportStreams = array();

		if (isset($rData['review'])) {
			$rReview = true;

			foreach ($rData['review'] as $rImportStream) {
				if ($rImportStream['channel_id'] || !$rImportStream['tvg_id']) {
				} else {
					$rEPG = findEPG($rImportStream['tvg_id']);

					if (!isset($rEPG)) {
					} else {
						$rImportStream['epg_id'] = $rEPG['epg_id'];
						$rImportStream['channel_id'] = $rEPG['channel_id'];

						if (empty($rEPG['epg_lang'])) {
						} else {
							$rImportStream['epg_lang'] = $rEPG['epg_lang'];
						}
					}
				}

				$rImportStreams[] = $rImportStream;
			}
		} else {
			if (isset($_FILES['m3u_file'])) {
				if (hasPermissions('adv', 'import_streams')) {
					if (!(empty($_FILES['m3u_file']['tmp_name']) || strtolower(pathinfo(explode('?', $_FILES['m3u_file']['name'])[0], PATHINFO_EXTENSION)) != 'm3u')) {
						$rResults = parseM3U($_FILES['m3u_file']['tmp_name']);

						if (0 >= count($rResults)) {
						} else {
							$rEPGDatabase = $rSourceDatabase = $rStreamDatabase = array();
							$db->query('SELECT `id`, `stream_display_name`, `stream_source`, `channel_id` FROM `streams` WHERE `type` = 1;');

							foreach ($db->get_rows() as $rRow) {
								$rName = preg_replace('/[^A-Za-z0-9 ]/', '', strtolower($rRow['stream_display_name']));

								if (empty($rName)) {
								} else {
									$rStreamDatabase[$rName] = $rRow['id'];
								}

								$rEPGDatabase[$rRow['channel_id']] = $rRow['id'];

								foreach (json_decode($rRow['stream_source'], true) as $rSource) {
									if (empty($rSource)) {
									} else {
										$rSourceDatabase[md5(preg_replace('(^https?://)', '', str_replace(' ', '%20', $rSource)))] = $rRow['id'];
									}
								}
							}
							$rEPGMatch = $rEPGScan = array();
							$i = 0;

							foreach ($rResults as $rResult) {
								list($rTag) = $rResult->getExtTags();

								if (!$rTag) {
								} else {
									if (!$rTag->getAttribute('tvg-id')) {
									} else {
										$rID = $rTag->getAttribute('tvg-id');
										$rEPGScan[$rID][] = $i;
									}
								}

								$i++;
							}

							if (0 >= count($rEPGScan)) {
							} else {
								$db->query('SELECT `id`, `data` FROM `epg`;');

								if (0 >= $db->num_rows()) {
								} else {
									foreach ($db->get_rows() as $rRow) {
										foreach (json_decode($rRow['data'], true) as $rChannelID => $rChannelData) {
											if (!isset($rEPGScan[$rChannelID])) {
											} else {
												if (0 < count($rChannelData['langs'])) {
													$rEPGLang = $rChannelData['langs'][0];
												} else {
													$rEPGLang = '';
												}

												foreach ($rEPGScan[$rChannelID] as $i) {
													$rEPGMatch[$i] = array('channel_id' => $rChannelID, 'epg_lang' => $rEPGLang, 'epg_id' => intval($rRow['id']));
												}
											}
										}
									}
								}
							}

							$i = 0;

							foreach ($rResults as $rResult) {
								list($rTag) = $rResult->getExtTags();

								if (!$rTag) {
								} else {
									$rURL = $rResult->getPath();
									$rImportArray = array('stream_source' => array($rURL), 'stream_icon' => ($rTag->getAttribute('tvg-logo') ?: ''), 'stream_display_name' => ($rTag->getTitle() ?: ''), 'epg_id' => null, 'epg_lang' => null, 'channel_id' => null);

									if (!$rTag->getAttribute('tvg-id')) {
									} else {
										$rEPG = ($rEPGMatch[$i] ?: null);

										if (!isset($rEPG)) {
										} else {
											$rImportArray['epg_id'] = $rEPG['epg_id'];
											$rImportArray['channel_id'] = $rEPG['channel_id'];

											if (empty($rEPG['epg_lang'])) {
											} else {
												$rImportArray['epg_lang'] = $rEPG['epg_lang'];
											}
										}
									}

									$rBackupID = $rExistsID = null;
									$rSourceID = md5(preg_replace('(^https?://)', '', str_replace(' ', '%20', $rURL)));

									if (!isset($rSourceDatabase[$rSourceID])) {
									} else {
										$rExistsID = $rSourceDatabase[$rSourceID];
									}

									$rName = preg_replace('/[^A-Za-z0-9 ]/', '', strtolower($rTag->getTitle()));

									if (!empty($rName) && isset($rStreamDatabase[$rName])) {
										$rBackupID = $rStreamDatabase[$rName];
									} else {
										if (empty($rImportArray['channel_id']) || !isset($rEPGDatabase[$rImportArray['channel_id']])) {
										} else {
											$rBackupID = $rEPGDatabase[$rImportArray['channel_id']];
										}
									}

									if ($rBackupID && !$rExistsID && isset($rData['add_source_as_backup'])) {
										$db->query('SELECT `stream_source` FROM `streams` WHERE `id` = ?;', $rBackupID);

										if (0 >= $db->num_rows()) {
										} else {
											$rSources = (json_decode($db->get_row()['stream_source'], true) ?: array());
											$rSources[] = $rURL;
											$db->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = ?;', json_encode($rSources), $rBackupID);
											$rImportStreams[] = array('update' => true, 'id' => $rBackupID);
										}
									} else {
										if ($rExistsID && isset($rData['update_existing'])) {
											$rImportArray['id'] = $rExistsID;
											$rImportStreams[] = $rImportArray;
										} else {
											if ($rExistsID) {
											} else {
												$rImportStreams[] = $rImportArray;
											}
										}
									}
								}

								$i++;
							}
						}
					} else {
						return array('status' => STATUS_INVALID_FILE, 'data' => $rData);
					}
				} else {
					exit();
				}
			} else {
				$rImportArray = array('stream_source' => array(), 'stream_icon' => $rArray['stream_icon'], 'stream_display_name' => $rArray['stream_display_name'], 'epg_id' => $rArray['epg_id'], 'epg_lang' => $rArray['epg_lang'], 'channel_id' => $rArray['channel_id']);

				if (isset($rData['stream_source'])) {
					foreach ($rData['stream_source'] as $rID => $rURL) {
						if (0 >= strlen($rURL)) {
						} else {
							$rImportArray['stream_source'][] = $rURL;
						}
					}
				}

				$rImportStreams[] = $rImportArray;
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
					$rPrepare = prepareArray(array('category_type' => 'live', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
					$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (!$db->query($rQuery, ...$rPrepare['data'])) {
					} else {
						$rCategoryID = $db->last_insert_id();
						$rCategoryCreate[$rCategory] = $rCategoryID;
					}
				}
			}

			foreach ($rImportStreams as $rImportStream) {
				if (!$rImportStream['update']) {
					$rImportArray = $rArray;

					if (!$rSettings['download_images']) {
					} else {
						$rImportStream['stream_icon'] = CoreUtilities::downloadImage($rImportStream['stream_icon'], 1);
					}

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

						if (isset($rData['adaptive_link']) && 0 < count($rData['adaptive_link'])) {
							$rImportArray['adaptive_link'] = '[' . implode(',', array_map('intval', $rData['adaptive_link'])) . ']';
						} else {
							$rImportArray['adaptive_link'] = null;
						}
					}

					foreach (array_keys($rImportStream) as $rKey) {
						$rImportArray[$rKey] = $rImportStream[$rKey];
					}

					if (isset($rData['edit']) || isset($rImportStream['id'])) {
					} else {
						$rImportArray['order'] = getNextOrder();
					}

					$rImportArray['title_sync'] = ($rData['title_sync'] ?: null);

					if (!$rImportArray['title_sync']) {
					} else {
						list($rSyncID, $rSyncStream) = array_map('intval', explode('_', $rImportArray['title_sync']));
						$db->query('SELECT `stream_display_name` FROM `providers_streams` WHERE `provider_id` = ? AND `stream_id` = ?;', $rSyncID, $rSyncStream);

						if ($db->num_rows() != 1) {
						} else {
							$rImportArray['stream_display_name'] = $db->get_row()['stream_display_name'];
						}
					}

					$rPrepare = prepareArray($rImportArray);
					$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if ($db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = $db->last_insert_id();
						$rStreamExists = array();

						if (!(isset($rData['edit']) || isset($rImportStream['id']))) {
						} else {
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
								$rOD = intval(in_array($rServerID, ($rData['on_demand'] ?: array())));

								if ($rServer['parent'] == 'source') {
									$rParent = null;
								} else {
									$rParent = intval($rServer['parent']);
								}

								if (isset($rStreamExists[$rServerID])) {
									$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rServerID]);
								} else {
									$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES(?, ?, ?, ?);', $rInsertID, $rServerID, $rParent, $rOD);
								}
							}
						}

						foreach ($rStreamExists as $rServerID => $rDBID) {
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
							addToBouquet('stream', $rBouquet, $rInsertID);
						}

						if (!(isset($rData['edit']) || isset($rImportStream['id']))) {
						} else {
							foreach (getBouquets() as $rBouquet) {
								if (in_array($rBouquet['id'], $rBouquets)) {
								} else {
									removeFromBouquet('stream', $rBouquet['id'], $rInsertID);
								}
							}
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
			}

			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
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

		if (!isset($rData['c_days_to_restart'])) {
		} else {
			if (isset($rData['days_to_restart']) && preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $rData['time_to_restart'])) {
				$rTimeArray = array('days' => array(), 'at' => $rData['time_to_restart']);

				foreach ($rData['days_to_restart'] as $rID => $rDay) {
					$rTimeArray['days'][] = $rDay;
				}
				$rArray['auto_restart'] = json_encode($rTimeArray);
			} else {
				$rArray['auto_restart'] = '';
			}
		}

		foreach (array('gen_timestamps', 'allow_record', 'rtmp_output', 'fps_restart', 'stream_all', 'read_native') as $rKey) {
			if (!isset($rData['c_' . $rKey])) {
			} else {
				if (isset($rData[$rKey])) {
					$rArray[$rKey] = 1;
				} else {
					$rArray[$rKey] = 0;
				}
			}
		}

		if (!isset($rData['c_direct_source'])) {
		} else {
			if (isset($rData['direct_source'])) {
				$rArray['direct_source'] = 1;
			} else {
				$rArray['direct_source'] = 0;
				$rArray['direct_proxy'] = 0;
			}
		}

		if (!isset($rData['c_direct_proxy'])) {
		} else {
			if (isset($rData['direct_proxy'])) {
				$rArray['direct_proxy'] = 1;
				$rArray['direct_source'] = 1;
			} else {
				$rArray['direct_proxy'] = 0;
			}
		}

		foreach (array('tv_archive_server_id', 'vframes_server_id', 'tv_archive_duration', 'delay_minutes', 'probesize_ondemand', 'fps_threshold', 'llod') as $rKey) {
			if (!isset($rData['c_' . $rKey])) {
			} else {
				$rArray[$rKey] = intval($rData[$rKey]);
			}
		}

		if (!isset($rData['c_custom_sid'])) {
		} else {
			$rArray['custom_sid'] = $rData['custom_sid'];
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

			$rDeleteServers = $rStreamExists = array();
			$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

			foreach ($db->get_rows() as $rRow) {
				$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
			}
			$rBouquets = getBouquets();
			$rDelOptions = $rAddBouquet = $rDelBouquet = array();
			$rOptQuery = $rAddQuery = '';

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

				if (!isset($rData['c_user_agent'])) {
				} else {
					if (!(isset($rData['user_agent']) && 0 < strlen($rData['user_agent']))) {
					} else {
						$rDelOptions[1][] = $rStreamID;
						$rOptQuery .= '(' . intval($rStreamID) . ', 1, ' . $db->escape($rData['user_agent']) . '),';
					}
				}

				if (!isset($rData['c_http_proxy'])) {
				} else {
					if (!(isset($rData['http_proxy']) && 0 < strlen($rData['http_proxy']))) {
					} else {
						$rDelOptions[2][] = $rStreamID;
						$rOptQuery .= '(' . intval($rStreamID) . ', 2, ' . $db->escape($rData['http_proxy']) . '),';
					}
				}

				if (!isset($rData['c_cookie'])) {
				} else {
					if (!(isset($rData['cookie']) && 0 < strlen($rData['cookie']))) {
					} else {
						$rDelOptions[17][] = $rStreamID;
						$rOptQuery .= '(' . intval($rStreamID) . ', 17, ' . $db->escape($rData['cookie']) . '),';
					}
				}

				if (!isset($rData['c_headers'])) {
				} else {
					if (!(isset($rData['headers']) && 0 < strlen($rData['headers']))) {
					} else {
						$rDelOptions[19][] = $rStreamID;
						$rOptQuery .= '(' . intval($rStreamID) . ', 19, ' . $db->escape($rData['headers']) . '),';
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

			foreach ($rDelOptions as $rOptionID => $rDelIDs) {
				$rDelIDs = array_unique(array_map('intval', $rDelIDs));
				if (0 >= count($rDelIDs)) {
				} else {
					$db->query('DELETE FROM `streams_options` WHERE `stream_id` IN (' . implode(',', $rDelIDs) . ') AND `argument_id` = ?;', intval($rOptionID));
				}
			}

			if (empty($rOptQuery)) {
			} else {
				$rOptQuery = rtrim($rOptQuery, ',');
				$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES ' . $rOptQuery . ';');
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

			if (!isset($rData['restart_on_edit'])) {
			} else {
				APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => array_values($rStreamIDs)));
			}
		}

		return array('status' => STATUS_SUCCESS);
	}

	public static function move($rData, $db) {
		$rType = intval($rData['content_type']);
		$rSource = intval($rData['source_server']);
		$rReplacement = intval($rData['replacement_server']);

		if (!(0 < $rSource && 0 < $rReplacement && $rSource != $rReplacement)) {
		} else {
			$rExisting = array();

			if ($rType == 0) {
				$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_id` = ?;', $rReplacement);

				foreach ($db->get_rows() as $rRow) {
					$rExisting[] = intval($rRow['stream_id']);
				}
			} else {
				$db->query('SELECT `streams_servers`.`stream_id` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams_servers`.`server_id` = ? AND `streams`.`type` = ?;', $rReplacement, $rType);

				foreach ($db->get_rows() as $rRow) {
					$rExisting[] = intval($rRow['stream_id']);
				}
			}

			$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_id` = ?;', $rSource);

			foreach ($db->get_rows() as $rRow) {
				if (!in_array(intval($rRow['stream_id']), $rExisting)) {
				} else {
					$db->query('DELETE FROM `streams_servers` WHERE `stream_id` = ? AND `server_id` = ?;', $rRow['stream_id'], $rSource);
				}
			}

			if ($rType == 0) {
				$db->query('UPDATE `streams_servers` SET `server_id` = ? WHERE `server_id` = ?;', $rReplacement, $rSource);
			} else {
				$db->query('UPDATE `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` SET `streams_servers`.`server_id` = ? WHERE `streams_servers`.`server_id` = ? AND `streams`.`type` = ?;', $rReplacement, $rSource, $rType);
			}
		}

		return array('status' => STATUS_SUCCESS);
	}

	public static function replaceDNS($rData, $db) {
		$rOldDNS = str_replace('/', '\\/', $rData['old_dns']);
		$rNewDNS = str_replace('/', '\\/', $rData['new_dns']);
		$db->query('UPDATE `streams` SET `stream_source` = REPLACE(`stream_source`, ?, ?);', $rOldDNS, $rNewDNS);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rStreams = json_decode($rData['streams'], true);
		deleteStreams($rStreams, false);

		return array('status' => STATUS_SUCCESS);
	}
}
