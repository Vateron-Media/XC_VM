<?php

class API {
	public static $db = null;
	public static $rSettings = array();
	public static $rServers = array();
	public static $rProxyServers = array();
	public static $rUserInfo = array();

	public static function init($rUserID = null) {
		self::$rSettings = getSettings();
		self::$rServers = getStreamingServers('all');
		self::$rProxyServers = getProxyServers();

		if ($rUserID || !isset($_SESSION['hash'])) {
		} else {
			$rUserID = $_SESSION['hash'];
		}

		if (!$rUserID) {
		} else {
			self::$rUserInfo = getRegisteredUser($rUserID);
		}
	}

	private static function checkMinimumRequirements($rData) {

		switch (debug_backtrace()[1]['function']) {
			case 'scheduleRecording':
				return !empty($rData['title']) && !empty($rData['source_id']);

			case 'processProvider':
				return !empty($rData['ip']) && !empty($rData['port']) && !empty($rData['username']) && !empty($rData['password']) && !empty($rData['name']);

			case 'processBouquet':
				return !empty($rData['bouquet_name']);

			case 'processGroup':
				return !empty($rData['group_name']);

			case 'processPackage':
				return !empty($rData['package_name']);

			case 'processCategory':
				return !empty($rData['category_name']) && !empty($rData['category_type']);

			case 'processCode':
				return !empty($rData['code']);

			case 'reorderBouquet':
			case 'setChannelOrder':
				return is_array(json_decode($rData['stream_order_array'], true));

			case 'sortBouquets':
				return is_array(json_decode($rData['bouquet_order_array'], true));

			case 'blockIP':
			case 'processRTMPIP':
				return !empty($rData['ip']);

			case 'processChannel':
			case 'processStream':
			case 'processMovie':
			case 'processRadio':
				return !empty($rData['stream_display_name']) || isset($rData['review']) || isset($_FILES['m3u_file']);

			case 'processEpisode':
				return !empty($rData['series']) && is_numeric($rData['season_num']) && (isset($rData['multi']) || is_numeric($rData['episode']));

			case 'processSeries':
				return !empty($rData['title']);

			case 'processEPG':
				return !empty($rData['epg_name']) && !empty($rData['epg_file']);

			case 'massEditEpisodes':
			case 'massEditMovies':
			case 'massEditRadios':
			case 'massEditStreams':
			case 'massEditChannels':
			case 'massDeleteStreams':
				return is_array(json_decode($rData['streams'], true));

			case 'massEditSeries':
			case 'massDeleteSeries':
				return is_array(json_decode($rData['series'], true));

			case 'massEditLines':
			case 'massEditUsers':
				return is_array(json_decode($rData['users_selected'], true));

			case 'massEditMags':
			case 'massEditEnigmas':
				return is_array(json_decode($rData['devices_selected'], true));

			case 'processISP':
				return !empty($rData['isp']);

			case 'massDeleteMovies':
				return is_array(json_decode($rData['movies'], true));

			case 'massDeleteLines':
				return is_array(json_decode($rData['lines'], true));

			case 'massDeleteUsers':
				return is_array(json_decode($rData['users'], true));

			case 'massDeleteStations':
				return is_array(json_decode($rData['radios'], true));

			case 'massDeleteMags':
				return is_array(json_decode($rData['mags'], true));

			case 'massDeleteEnigmas':
				return is_array(json_decode($rData['enigmas'], true));

			case 'massDeleteEpisodes':
				return is_array(json_decode($rData['episodes'], true));

			case 'processMAG':
			case 'processEnigma':
				return !empty($rData['mac']);

			case 'processProfile':
				return !empty($rData['profile_name']);

			case 'processProxy':
			case 'processServer':
				return !empty($rData['server_name']) && !empty($rData['server_ip']);

			case 'installServer':
				return !empty($rData['ssh_port']) && !empty($rData['root_password']);

			case 'orderCategories':
				return is_array(json_decode($rData['categories'], true));

			case 'orderServers':
				return is_array(json_decode($rData['server_order'], true));

			case 'moveStreams':
				return !empty($rData['content_type']) && !empty($rData['source_server']) && !empty($rData['replacement_server']);

			case 'replaceDNS':
				return !empty($rData['old_dns']) && !empty($rData['new_dns']);

			case 'processUA':
				return !empty($rData['user_agent']);

			case 'processWatchFolder':
				return !empty($rData['folder_type']) && !empty($rData['selected_path']) && !empty($rData['server_id']);
		}

		return true;
	}

	public static function processBouquet($rData) {
		global $_;

		if (self::checkMinimumRequirements($rData)) {


			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'edit_bouquet')) {


					$rArray = overwriteData(getBouquet($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'add_bouquet')) {


					$rArray = verifyPostTable('bouquets', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (is_array(json_decode($rData['bouquet_data'], true))) {
				$rBouquetData = json_decode($rData['bouquet_data'], true);
				$rBouquetStreams = $rBouquetData['stream'];
				$rBouquetMovies = $rBouquetData['movies'];
				$rBouquetRadios = $rBouquetData['radios'];
				$rBouquetSeries = $rBouquetData['series'];
				$rRequiredIDs = confirmIDs(array_merge($rBouquetStreams, $rBouquetMovies, $rBouquetRadios));
				$rStreams = array();

				if (0 >= count($rRequiredIDs)) {
				} else {
					self::$db->query('SELECT `id`, `type` FROM `streams` WHERE `id` IN (' . implode(',', $rRequiredIDs) . ');');

					foreach (self::$db->get_rows() as $rRow) {
						if (intval($rRow['type']) != 3) {
						} else {
							$rRow['type'] = 1;
						}

						$rStreams[intval($rRow['type'])][] = intval($rRow['id']);
					}
				}

				if (count($rBouquetSeries) > 0) {
					self::$db->query('SELECT `id` FROM `streams_series` WHERE `id` IN (' . implode(',', $rBouquetSeries) . ');');

					foreach (self::$db->get_rows() as $rRow) {
						$rStreams[5][] = intval($rRow['id']);
					}
				}

				$rArray['bouquet_channels'] = array_intersect(array_map('intval', array_values($rBouquetStreams)), $rStreams[1] ?? []);
				$rArray['bouquet_movies'] = array_intersect(array_map('intval', array_values($rBouquetMovies)),	$rStreams[2] ?? []);
				$rArray['bouquet_radios'] = array_intersect(array_map('intval', array_values($rBouquetRadios)),	$rStreams[4] ?? []);
				$rArray['bouquet_series'] = array_intersect(array_map('intval', array_values($rBouquetSeries)),	$rStreams[5] ?? []);
			} else {
				if (isset($rData['edit'])) {
					return array('status' => STATUS_FAILURE, 'data' => $rData);
				}
			}

			if (!isset($rData['edit'])) {
				self::$db->query('SELECT MAX(`bouquet_order`) AS `max` FROM `bouquets`;');
				$rArray['bouquet_order'] = intval(self::$db->get_row()['max']) + 1;
			}

			$rPrepare = prepareArray($rArray);
			$rQuery = 'REPLACE INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if (self::$db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = self::$db->last_insert_id();
				scanBouquet($rInsertID);

				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			}

			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processCode($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				$rArray = overwriteData(getCode($rData['edit']), $rData);
				$rOrigCode = $rArray['code'];
			} else {
				$rArray = verifyPostTable('access_codes', $rData);
				$rOrigCode = null;
				unset($rArray['id']);
			}

			if (isset($rData['enabled'])) {
				$rArray['enabled'] = 1;
			} else {
				$rArray['enabled'] = 0;
			}

			if (!isset($rData['groups'])) {
			} else {
				$rArray['groups'] = array();

				foreach ($rData['groups'] as $rGroupID) {
					$rArray['groups'][] = intval($rGroupID);
				}
			}

			if (in_array($rData['type'], array(0, 1, 3, 4))) {
				$rArray['groups'] = '[' . implode(',', array_map('intval', $rArray['groups'])) . ']';
			} else {
				$rArray['groups'] = '[]';
			}

			if (isset($rData['whitelist'])) {
			} else {
				$rArray['whitelist'] = '[]';
			}

			if ($rData['type'] != 2 && strlen($rData['code']) < 8) {
				return array('status' => STATUS_CODE_LENGTH, 'data' => $rData);
			}

			if ($rData['type'] == 2 && empty($rData['code'])) {

				return array('status' => STATUS_INVALID_CODE, 'data' => $rData);
			}

			if (in_array($rData['code'], array('admin', 'stream', 'images', 'player_api', 'player', 'playlist', 'epg', 'live', 'movie', 'series', 'status', 'nginx_status', 'get', 'panel_api', 'xmltv', 'probe', 'thumb', 'timeshift', 'auth', 'vauth', 'tsauth', 'hls', 'play', 'key', 'api', 'c'))) {
				return array('status' => STATUS_RESERVED_CODE, 'data' => $rData);
			}

			if (isset($rData['edit'])) {
				self::$db->query('SELECT `id` FROM `access_codes` WHERE `code` = ? AND `id` <> ?;', $rData['code'], $rData['edit']);
			} else {
				self::$db->query('SELECT `id` FROM `access_codes` WHERE `code` = ?;', $rData['code']);
			}

			if (0 >= self::$db->num_rows()) {
				$rPrepare = prepareArray($rArray);


				$rQuery = 'REPLACE INTO `access_codes`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (self::$db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = self::$db->last_insert_id();
					updateCodes();

					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID, 'orig_code' => $rOrigCode, 'new_code' => $rData['code']));
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}

			return array('status' => STATUS_EXISTS_CODE, 'data' => $rData);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processHMAC($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				$rArray = overwriteData(getHMACToken($rData['edit']), $rData);
			} else {
				$rArray = verifyPostTable('hmac_keys', $rData);
				unset($rArray['id']);
			}

			if (isset($rData['enabled'])) {
				$rArray['enabled'] = 1;
			} else {
				$rArray['enabled'] = 0;
			}

			if (!($rData['keygen'] != 'HMAC KEY HIDDEN' && strlen($rData['keygen']) != 32)) {
				if (strlen($rData['notes']) != 0) {
					if (isset($rData['edit'])) {
						if ($rData['keygen'] != 'HMAC KEY HIDDEN') {
							self::$db->query('SELECT `id` FROM `hmac_keys` WHERE `key` = ? AND `id` <> ?;', CoreUtilities::encryptData($rData['keygen'], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA), $rData['edit']);

							if (0 >= self::$db->num_rows()) {
							} else {
								return array('status' => STATUS_EXISTS_HMAC, 'data' => $rData);
							}
						}
					} else {
						self::$db->query('SELECT `id` FROM `hmac_keys` WHERE `key` = ?;', CoreUtilities::encryptData($rData['keygen'], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA));

						if (0 >= self::$db->num_rows()) {
						} else {
							return array('status' => STATUS_EXISTS_HMAC, 'data' => $rData);
						}
					}

					if ($rData['keygen'] != 'HMAC KEY HIDDEN') {
						$rArray['key'] = CoreUtilities::encryptData($rData['keygen'], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
					}

					$rPrepare = prepareArray($rArray);
					$rQuery = 'REPLACE INTO `hmac_keys`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (self::$db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = self::$db->last_insert_id();

						return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
					}
					return array('status' => STATUS_FAILURE, 'data' => $rData);
				}
				return array('status' => STATUS_NO_DESCRIPTION, 'data' => $rData);
			}
			return array('status' => STATUS_NO_KEY, 'data' => $rData);
		}
		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function reorderBouquet($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rOrder = json_decode($rData['stream_order_array'], true);
			$rOrder['stream'] = confirmIDs($rOrder['stream']);
			$rOrder['series'] = confirmIDs($rOrder['series']);
			$rOrder['movie'] = confirmIDs($rOrder['movie']);
			$rOrder['radio'] = confirmIDs($rOrder['radio']);
			self::$db->query('UPDATE `bouquets` SET `bouquet_channels` = ?, `bouquet_series` = ?, `bouquet_movies` = ?, `bouquet_radios` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rOrder['stream'])) . ']', '[' . implode(',', array_map('intval', $rOrder['series'])) . ']', '[' . implode(',', array_map('intval', $rOrder['movie'])) . ']', '[' . implode(',', array_map('intval', $rOrder['radio'])) . ']', $rData['reorder']);

			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rData['reorder']));
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function editAdminProfile($rData) {
		global $allowedLangs;
		if (self::checkMinimumRequirements($rData)) {
			if (0 >= strlen($rData['email']) || filter_var($rData['email'], FILTER_VALIDATE_EMAIL)) {


				if (0 < strlen($rData['password'])) {
					$rPassword = cryptPassword($rData['password']);
				} else {
					$rPassword = self::$rUserInfo['password'];
				}

				if (ctype_xdigit($rData['api_key']) && strlen($rData['api_key']) == 32) {
				} else {
					$rData['api_key'] = '';
				}

				if (!in_array($rData['lang'], $allowedLangs)) {
					$rData['lang'] = 'en';
				}

				self::$db->query('UPDATE `users` SET `password` = ?, `email` = ?, `theme` = ?, `hue` = ?, `timezone` = ?, `api_key` = ?, `lang` = ? WHERE `id` = ?;', $rPassword, $rData['email'], $rData['theme'], $rData['hue'], $rData['timezone'], $rData['api_key'], $rData['lang'], self::$rUserInfo['id']);

				return array('status' => STATUS_SUCCESS);
			}

			return array('status' => STATUS_INVALID_EMAIL);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function blockIP($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (validateCIDR($rData['ip'])) {
				$rArray = array('ip' => $rData['ip'], 'notes' => $rData['notes'], 'date' => time());
				touch(FLOOD_TMP_PATH . 'block_' . $rData['ip']);
				$rPrepare = prepareArray($rArray);
				$rQuery = 'REPLACE INTO `blocked_ips`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
				} else {
					$rInsertID = self::$db->last_insert_id();
				}

				if (isset($rInsertID)) {
					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}

			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function sortBouquets($rData) {
		if (self::checkMinimumRequirements($rData)) {
			set_time_limit(0);
			ini_set('mysql.connect_timeout', 0);
			ini_set('max_execution_time', 0);
			ini_set('default_socket_timeout', 0);
			$rOrder = json_decode($rData['bouquet_order_array'], true);
			$rSort = 1;

			foreach ($rOrder as $rBouquetID) {
				self::$db->query('UPDATE `bouquets` SET `bouquet_order` = ? WHERE `id` = ?;', $rSort, $rBouquetID);
				$rSort++;
			}

			if (isset($rData['confirmReplace'])) {
				$rUsers = getUserBouquets();

				foreach ($rUsers as $rUser) {
					$rBouquet = json_decode($rUser['bouquet'], true);
					$rBouquet = array_map('intval', sortArrayByArray($rBouquet, $rOrder));
					self::$db->query('UPDATE `lines` SET `bouquet` = ? WHERE `id` = ?;', '[' . implode(',', $rBouquet) . ']', $rUser['id']);
					CoreUtilities::updateLine($rUser['id']);
				}
				$rPackages = getPackages();

				foreach ($rPackages as $rPackage) {
					$rBouquet = json_decode($rPackage['bouquets'], true);
					$rBouquet = array_map('intval', sortArrayByArray($rBouquet, $rOrder));
					self::$db->query('UPDATE `users_packages` SET `bouquets` = ? WHERE `id` = ?;', '[' . implode(',', $rBouquet) . ']', $rPackage['id']);
				}

				return array('status' => STATUS_SUCCESS_REPLACE);
			} else {
				return array('status' => STATUS_SUCCESS);
			}
		} else {



			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function setChannelOrder($rData) {
		if (self::checkMinimumRequirements($rData)) {
			set_time_limit(0);
			ini_set('mysql.connect_timeout', 0);
			ini_set('max_execution_time', 0);
			ini_set('default_socket_timeout', 0);
			$rOrder = json_decode($rData['stream_order_array'], true);
			$rSort = 0;

			foreach ($rOrder as $rStream) {
				self::$db->query('UPDATE `streams` SET `order` = ? WHERE `id` = ?;', $rSort, $rStream);
				$rSort++;
			}

			return array('status' => STATUS_SUCCESS);
		} else {



			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function processChannel($rData) {
		if (self::checkMinimumRequirements($rData)) {
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
				// video_files comes as a JSON string from the form, it needs to be decoded
				$rVideoFiles = $rData['video_files'];
				if (is_string($rVideoFiles)) {
					$rVideoFiles = json_decode($rVideoFiles, true);
				}
				$rArray['stream_source'] = is_array($rVideoFiles) ? $rVideoFiles : [];
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

					if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
					} else {
						$rBouquetID = self::$db->last_insert_id();
						$rBouquetCreate[$rBouquet] = $rBouquetID;
					}
				}
				$rCategoryCreate = array();

				foreach (json_decode($rData['category_create_list'], true) as $rCategory) {
					$rPrepare = prepareArray(array('category_type' => 'live', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
					$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
					} else {
						$rCategoryID = self::$db->last_insert_id();
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

				if (!self::$rSettings['download_images']) {
				} else {
					$rArray['stream_icon'] = CoreUtilities::downloadImage($rArray['stream_icon'], 3);
				}

				if (isset($rData['edit'])) {
				} else {
					$rArray['order'] = getNextOrder();
				}

				$rPrepare = prepareArray($rArray);
				$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (self::$db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = self::$db->last_insert_id();
					$rStreamExists = array();

					if (!isset($rData['edit'])) {
					} else {
						self::$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);

						foreach (self::$db->get_rows() as $rRow) {
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
								self::$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rServerID]);
							} else {
								self::$db->query("INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`, `pids_create_channel`, `cchannel_rsources`) VALUES(?, ?, ?, ?, '[]', '[]');", $rInsertID, $rServerID, $rParent, $rOD);
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
						self::$db->query("UPDATE `streams_servers` SET `pids_create_channel` = '[]', `cchannel_rsources` = '[]' WHERE `stream_id` = ?;", $rInsertID);
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
		} else {



			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function processEPG($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'epg_edit')) {
					$rArray = overwriteData(getEPG($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'add_epg')) {
					$rArray = verifyPostTable('epg', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			$rPrepare = prepareArray($rArray);
			$rQuery = 'REPLACE INTO `epg`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if (self::$db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = self::$db->last_insert_id();

				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			}

			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processProvider($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'streams')) {


					$rArray = overwriteData(getStreamProvider($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'streams')) {


					$rArray = verifyPostTable('providers', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			foreach (array('enabled', 'ssl', 'hls', 'legacy') as $rKey) {
				if (isset($rData[$rKey])) {
					$rArray[$rKey] = 1;
				} else {
					$rArray[$rKey] = 0;
				}
			}

			if (isset($rData['edit'])) {
				self::$db->query('SELECT `id` FROM `providers` WHERE `ip` = ? AND `username` = ? AND `id` <> ? LIMIT 1;', $rArray['ip'], $rArray['username'], $rData['edit']);
			} else {
				self::$db->query('SELECT `id` FROM `providers` WHERE `ip` = ? AND `username` = ? LIMIT 1;', $rArray['ip'], $rArray['username']);
			}

			if (0 >= self::$db->num_rows()) {
				$rPrepare = prepareArray($rArray);


				$rQuery = 'REPLACE INTO `providers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (self::$db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = self::$db->last_insert_id();

					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}

			return array('status' => STATUS_EXISTS_IP, 'data' => $rData);
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function processEpisode($rData) {
		if (self::checkMinimumRequirements($rData)) {
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

			if (0 >= $rArray['transcode_profile_id']) {
			} else {
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

					if (0 < strlen(self::$rSettings['tmdb_language'])) {
						$rTMDB = new TMDB(self::$rSettings['tmdb_api_key'], self::$rSettings['tmdb_language']);
					} else {
						$rTMDB = new TMDB(self::$rSettings['tmdb_api_key']);
					}

					$rJSON = json_decode($rTMDB->getSeason($rData['tmdb_id'], intval($rData['season_num']))->getJSON(), true);

					foreach ($rData as $rKey => $rFilename) {
						$rSplit = explode('_', $rKey);

						if (!($rSplit[0] == 'episode' && $rSplit[2] == 'name')) {
						} else {
							if (0 >= strlen($rData['episode_' . $rSplit[1] . '_num'])) {
							} else {
								$rImportArray = array('filename' => '', 'properties' => array(), 'name' => '', 'episode' => 0, 'target_container' => '');
								$rEpisodeNum = intval($rData['episode_' . $rSplit[1] . '_num']);
								$rImportArray['filename'] = 's:' . $rData['server'] . ':' . $rData['season_folder'] . $rFilename;
								$rImage = '';

								if (isset($rData['addName1']) && isset($rData['addName2'])) {
									$rImportArray['name'] = $rSeries['title'] . ' - S' . sprintf('%02d', intval($rData['season_num'])) . 'E' . sprintf('%02d', $rEpisodeNum) . ' - ';
								} else {
									if (isset($rData['addName1'])) {
										$rImportArray['name'] = $rSeries['title'] . ' - ';
									} else {
										if (!isset($rData['addName2'])) {
										} else {
											$rImportArray['name'] = 'S' . sprintf('%02d', intval($rData['season_num'])) . 'E' . sprintf('%02d', $rEpisodeNum) . ' - ';
										}
									}
								}

								$rImportArray['episode'] = $rEpisodeNum;

								foreach ($rJSON['episodes'] as $rEpisode) {
									if (intval($rEpisode['episode_number']) != $rEpisodeNum) {
									} else {
										if (0 >= strlen($rEpisode['still_path'])) {
										} else {
											$rImage = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rEpisode['still_path'];

											if (!self::$rSettings['download_images']) {
											} else {
												$rImage = CoreUtilities::downloadImage($rImage, 5);
											}
										}

										$rImportArray['name'] .= $rEpisode['name'];
										$rSeconds = intval($rSeries['episode_run_time']) * 60;
										$rImportArray['properties'] = array('tmdb_id' => $rEpisode['id'], 'release_date' => $rEpisode['air_date'], 'plot' => $rEpisode['overview'], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'movie_image' => $rImage, 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rEpisode['vote_average'], 'season' => $rData['season_num']);

										if (strlen($rImportArray['properties']['movie_image'][0]) != 0) {
										} else {
											unset($rImportArray['properties']['movie_image']);
										}
									}
								}

								if (strlen($rImportArray['name']) != 0) {
								} else {
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

				if (!self::$rSettings['download_images']) {
				} else {
					$rData['movie_image'] = CoreUtilities::downloadImage($rData['movie_image'], 5);
				}

				$rSeconds = intval($rData['episode_run_time']) * 60;
				$rImportArray['properties'] = array('release_date' => $rData['release_date'], 'plot' => $rData['plot'], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'movie_image' => $rData['movie_image'], 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rData['rating'], 'season' => $rData['season_num'], 'tmdb_id' => $rData['tmdb_id']);

				if (strlen($rImportArray['properties']['movie_image'][0]) != 0) {
				} else {
					unset($rImportArray['properties']['movie_image']);
				}

				if (!$rData['direct_proxy']) {
				} else {
					$rExtension = pathinfo(explode('?', $rData['stream_source'])[0])['extension'];

					if ($rExtension) {
						$rImportArray['target_container'] = $rExtension;
					} else {
						if ($rImportArray['target_container']) {
						} else {
							$rImportArray['target_container'] = 'mp4';
						}
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
				} else {
					if (empty($rData['target_container'])) {
						$rArray['target_container'] = pathinfo(explode('?', $rImportArray['filename'])[0])['extension'];
					} else {
						$rArray['target_container'] = $rData['target_container'];
					}
				}

				$rPrepare = prepareArray($rArray);
				$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (self::$db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = self::$db->last_insert_id();
					self::$db->query('DELETE FROM `streams_episodes` WHERE `stream_id` = ?;', $rInsertID);
					self::$db->query('INSERT INTO `streams_episodes`(`season_num`, `series_id`, `stream_id`, `episode_num`) VALUES(?, ?, ?, ?);', $rData['season_num'], $rData['series'], $rInsertID, $rImportArray['episode']);
					updateSeriesAsync(intval($rData['series']));
					$rStreamExists = array();

					if (!isset($rData['edit'])) {
					} else {
						self::$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);

						foreach (self::$db->get_rows() as $rRow) {
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

							if (isset($rStreamExists[$rServerID])) {
							} else {
								self::$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `on_demand`) VALUES(?, ?, 0);', $rInsertID, $rServerID);
							}
						}
					}

					foreach ($rStreamExists as $rServerID => $rDBID) {
						if (in_array($rServerID, $rStreamsAdded)) {
						} else {
							deleteStream($rInsertID, $rServerID, true, false);
						}
					}

					if (!$rRestart) {
					} else {
						$rRestartIDs[] = $rInsertID;
					}

					self::$db->query('UPDATE `streams_series` SET `last_modified` = ? WHERE `id` = ?;', time(), $rData['streams_series']);
					CoreUtilities::updateStream($rInsertID);
				} else {
					return array('status' => STATUS_FAILURE);
				}
			}

			if (!$rRestart) {
			} else {
				APIRequest(array('action' => 'vod', 'sub' => 'start', 'stream_ids' => $rRestartIDs));
			}

			if (isset($rData['multi'])) {
				return array('status' => STATUS_SUCCESS_MULTI, 0 => array('series_id' => $rData['series']));
			}

			return array('status' => STATUS_SUCCESS, 'data' => array('series_id' => $rData['series'], 'insert_id' => $rInsertID));
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function massEditEpisodes($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {
			$rArray = array();


			if (!isset($rData['c_movie_symlink'])) {
			} else {
				if (isset($rData['movie_symlink'])) {
					$rArray['movie_symlink'] = 1;
				} else {
					$rArray['movie_symlink'] = 0;
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

			if (!isset($rData['c_read_native'])) {
			} else {
				if (isset($rData['read_native'])) {
					$rArray['read_native'] = 1;
				} else {
					$rArray['read_native'] = 0;
				}
			}

			if (!isset($rData['c_remove_subtitles'])) {
			} else {
				if (isset($rData['remove_subtitles'])) {
					$rArray['remove_subtitles'] = 1;
				} else {
					$rArray['remove_subtitles'] = 0;
				}
			}

			if (!isset($rData['c_target_container'])) {
			} else {
				$rArray['target_container'] = $rData['target_container'];
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

			$rStreamIDs = confirmIDs(json_decode($rData['streams'], true));

			if (0 >= count($rStreamIDs)) {
			} else {
				if (!isset($rData['c_serie_name'])) {
				} else {
					self::$db->query('UPDATE `streams_episodes` SET `series_id` = ? WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');', $rData['serie_name']);
					self::$db->query('UPDATE `streams` SET `series_no` = ? WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');', $rData['serie_name']);
				}

				$rPrepare = prepareArray($rArray);

				if (0 >= count($rPrepare['data'])) {
				} else {
					$rQuery = 'UPDATE `streams` SET ' . $rPrepare['update'] . ' WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');';
					self::$db->query($rQuery, ...$rPrepare['data']);
				}

				$rDeleteServers = $rQueueMovies = $rProcessServers = $rStreamExists = array();
				self::$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

				foreach (self::$db->get_rows() as $rRow) {
					$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
					$rProcessServers[intval($rRow['stream_id'])][] = intval($rRow['server_id']);
				}
				$rAddQuery = '';

				foreach ($rStreamIDs as $rStreamID) {
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

									if (isset($rStreamExists[$rStreamID][$rServerID])) {
									} else {
										$rAddQuery .= '(' . intval($rStreamID) . ', ' . intval($rServerID) . '),';
										$rProcessServers[$rStreamID][] = $rServerID;
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

									if (($rKey = array_search($rServerID, $rProcessServers[$rStreamID])) === false) {
									} else {
										unset($rProcessServers[$rStreamID][$rKey]);
									}
								}
							}
						}
					}

					if (!isset($rData['reencode_on_edit'])) {
					} else {
						foreach ($rProcessServers[$rStreamID] as $rServerID) {
							$rQueueMovies[$rServerID][] = $rStreamID;
						}
					}
				}

				foreach ($rDeleteServers as $rServerID => $rDeleteIDs) {
					deleteStreamsByServer($rDeleteIDs, $rServerID, true);
				}

				if (empty($rAddQuery)) {
				} else {
					$rAddQuery = rtrim($rAddQuery, ',');
					self::$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`) VALUES ' . $rAddQuery . ';');
				}

				CoreUtilities::updateStreams($rStreamIDs);

				if (!isset($rData['reencode_on_edit'])) {
				} else {
					foreach ($rQueueMovies as $rServerID => $rQueueIDs) {
						CoreUtilities::queueMovies($rQueueIDs, $rServerID);
					}
				}

				if (!isset($rData['reprocess_tmdb'])) {
				} else {
					CoreUtilities::refreshMovies($rStreamIDs, 3);
				}
			}

			return array('status' => STATUS_SUCCESS);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processGroup($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'edit_group')) {


					$rArray = overwriteData(getMemberGroup($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'add_group')) {


					$rArray = verifyPostTable('users_groups', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			foreach (array('is_admin', 'is_reseller', 'allow_restrictions', 'create_sub_resellers', 'delete_users', 'allow_download', 'can_view_vod', 'reseller_client_connection_logs', 'allow_change_bouquets', 'allow_change_username', 'allow_change_password') as $rSelection) {
				if (isset($rData[$rSelection])) {
					$rArray[$rSelection] = 1;
				} else {
					$rArray[$rSelection] = 0;
				}
			}

			if ($rArray['can_delete'] || !isset($rData['edit'])) {
			} else {
				$rGroup = getMemberGroup($rData['edit']);
				$rArray['is_admin'] = $rGroup['is_admin'];
				$rArray['is_reseller'] = $rGroup['is_reseller'];
			}

			$rArray['allowed_pages'] = array_values(json_decode($rData['permissions_selected'], true));

			if (strlen($rData['group_name']) != 0) {


				$rArray['subresellers'] = '[' . implode(',', array_map('intval', json_decode($rData['groups_selected'], true))) . ']';
				$rArray['notice_html'] = htmlentities($rData['notice_html']);
				$rPrepare = prepareArray($rArray);
				$rQuery = 'REPLACE INTO `users_groups`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (self::$db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = self::$db->last_insert_id();
					$rPackages = json_decode($rData['packages_selected'], true);

					foreach ($rPackages as $rPackage) {
						self::$db->query('SELECT `groups` FROM `users_packages` WHERE `id` = ?;', $rPackage);

						if (self::$db->num_rows() != 1) {
						} else {
							$rGroups = json_decode(self::$db->get_row()['groups'], true);

							if (in_array($rInsertID, $rGroups)) {
							} else {
								$rGroups[] = $rInsertID;
								self::$db->query('UPDATE `users_packages` SET `groups` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rGroups)) . ']', $rPackage);
							}
						}
					}
					self::$db->query("SELECT `id`, `groups` FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rInsertID);

					foreach (self::$db->get_rows() as $rRow) {
						if (in_array($rRow['id'], $rPackages)) {
						} else {
							$rGroups = json_decode($rRow['groups'], true);

							if (($rKey = array_search($rInsertID, $rGroups)) === false) {
							} else {
								unset($rGroups[$rKey]);
								self::$db->query('UPDATE `users_packages` SET `groups` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rGroups)) . ']', $rRow['id']);
							}
						}
					}

					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
				} else {
					return array('status' => STATUS_FAILURE, 'data' => $rData);
				}
			} else {
				return array('status' => STATUS_INVALID_NAME, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function processISP($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'block_isps')) {


					$rArray = overwriteData(getISP($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'block_isps')) {


					$rArray = verifyPostTable('blocked_isps', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (isset($rData['blocked'])) {
				$rArray['blocked'] = 1;
			} else {
				$rArray['blocked'] = 0;
			}

			if (strlen($rArray['isp']) != 0) {
				$rPrepare = prepareArray($rArray);


				$rQuery = 'REPLACE INTO `blocked_isps`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (self::$db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = self::$db->last_insert_id();

					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}

			return array('status' => STATUS_INVALID_NAME, 'data' => $rData);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processLogin($rData, $rBypassRecaptcha = false) {
		return Authenticator::login(self::$db, self::$rSettings, $rData, $rBypassRecaptcha);
	}

	public static function massDeleteStreams($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {


			$rStreams = json_decode($rData['streams'], true);
			deleteStreams($rStreams, false);

			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function massDeleteMovies($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {


			$rMovies = json_decode($rData['movies'], true);
			deleteStreams($rMovies, true);

			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function massDeleteLines($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {


			$rLines = json_decode($rData['lines'], true);
			deleteLines($rLines);

			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function massDeleteUsers($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {


			$rUsers = json_decode($rData['users'], true);
			deleteUser($rUsers);

			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function massDeleteStations($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {


			$rStreams = json_decode($rData['radios'], true);
			deleteStreams($rStreams, false);

			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function massDeleteMags($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {


			$rMags = json_decode($rData['mags'], true);
			deleteMAGs($rMags);

			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function massDeleteEnigmas($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {


			$rEnigmas = json_decode($rData['enigmas'], true);
			deleteEnigmas($rEnigmas);

			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function massDeleteSeries($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {


			$rSeries = json_decode($rData['series'], true);
			deleteSeriesMass($rSeries);

			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function massDeleteEpisodes($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {


			$rEpisodes = json_decode($rData['episodes'], true);
			deleteStreams($rEpisodes, true);

			return array('status' => STATUS_SUCCESS);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processMovie($rData) {
		if (self::checkMinimumRequirements($rData)) {
			set_time_limit(0);
			ini_set('mysql.connect_timeout', 0);
			ini_set('max_execution_time', 0);
			ini_set('default_socket_timeout', 0);

			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'edit_movie')) {


					$rArray = overwriteData(getStream($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'add_movie')) {


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

				if (0 < strlen(self::$rSettings['tmdb_language'])) {
					$rTMDB = new TMDB(self::$rSettings['tmdb_api_key'], self::$rSettings['tmdb_language']);
				} else {
					$rTMDB = new TMDB(self::$rSettings['tmdb_api_key']);
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

							if (!self::$rSettings['download_images']) {
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
					if (hasPermissions('adv', 'import_movies')) {
						$rStreamDatabase = array();


						self::$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 2;');

						foreach (self::$db->get_rows() as $rRow) {
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
						if (hasPermissions('adv', 'import_movies')) {
							$rStreamDatabase = array();


							self::$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 2;');

							foreach (self::$db->get_rows() as $rRow) {
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

						if (!self::$rSettings['download_images']) {
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

						if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rBouquetID = self::$db->last_insert_id();
							$rBouquetCreate[$rBouquet] = $rBouquetID;
						}
					}

					foreach (json_decode($rData['category_create_list'], true) as $rCategory) {
						$rPrepare = prepareArray(array('category_type' => 'movie', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
						$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rCategoryID = self::$db->last_insert_id();
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
						$rImportArray['order'] = getNextOrder();
					}

					$rImportArray['tmdb_id'] = ($rImportStream['movie_properties']['tmdb_id'] ?: null);
					$rSync = $rImportArray['async'];
					unset($rImportArray['async']);
					$rPrepare = prepareArray($rImportArray);
					$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (self::$db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = self::$db->last_insert_id();
						$rStreamExists = array();

						if (!isset($rData['edit'])) {
						} else {
							self::$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);

							foreach (self::$db->get_rows() as $rRow) {
								$rStreamExists[intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
							}
						}

						$rPath = $rImportArray['stream_source'][0];

						if (substr($rPath, 0, 2) != 's:') {
						} else {
							$rSplit = explode(':', $rPath, 3);
							$rPath = $rSplit[2];
						}

						self::$db->query('UPDATE `watch_logs` SET `status` = 1, `stream_id` = ? WHERE `filename` = ? AND `type` = 1;', $rInsertID, $rPath);
						$rStreamsAdded = array();
						$rServerTree = json_decode($rData['server_tree_data'], true);

						foreach ($rServerTree as $rServer) {
							if ($rServer['parent'] == '#') {
							} else {
								$rServerID = intval($rServer['id']);
								$rStreamsAdded[] = $rServerID;

								if (isset($rStreamExists[$rServerID])) {
								} else {
									self::$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `on_demand`) VALUES(?, ?, 0);', $rInsertID, $rServerID);
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

						foreach (getBouquets() as $rBouquet) {
							if (in_array($rBouquet['id'], $rBouquets)) {
							} else {
								removeFromBouquet('movie', $rBouquet['id'], $rInsertID);
							}
						}

						if (!$rSync) {
						} else {
							self::$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(1, ?, 0);', $rInsertID);
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

	public static function massEditMovies($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {
			$rArray = array();


			if (!isset($rData['c_movie_symlink'])) {
			} else {
				if (isset($rData['movie_symlink'])) {
					$rArray['movie_symlink'] = 1;
				} else {
					$rArray['movie_symlink'] = 0;
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

			if (!isset($rData['c_read_native'])) {
			} else {
				if (isset($rData['read_native'])) {
					$rArray['read_native'] = 1;
				} else {
					$rArray['read_native'] = 0;
				}
			}

			if (!isset($rData['c_remove_subtitles'])) {
			} else {
				if (isset($rData['remove_subtitles'])) {
					$rArray['remove_subtitles'] = 1;
				} else {
					$rArray['remove_subtitles'] = 0;
				}
			}

			if (!isset($rData['c_target_container'])) {
			} else {
				$rArray['target_container'] = $rData['target_container'];
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
					self::$db->query('SELECT `id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

					foreach (self::$db->get_rows() as $rRow) {
						$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: array());
					}
				}

				$rDeleteServers = $rQueueMovies = $rProcessServers = $rStreamExists = array();
				self::$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

				foreach (self::$db->get_rows() as $rRow) {
					$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
					$rProcessServers[intval($rRow['stream_id'])][] = intval($rRow['server_id']);
				}
				$rBouquets = getBouquets();
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
						self::$db->query($rQuery, ...$rPrepare['data']);
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

									if (isset($rStreamExists[$rStreamID][$rServerID])) {
									} else {
										$rAddQuery .= '(' . intval($rStreamID) . ', ' . intval($rServerID) . '),';
										$rProcessServers[$rStreamID][] = $rServerID;
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

				if (empty($rAddQuery)) {
				} else {
					$rAddQuery = rtrim($rAddQuery, ',');
					self::$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`) VALUES ' . $rAddQuery . ';');
				}

				CoreUtilities::updateStreams($rStreamIDs);

				if (!isset($rData['reencode_on_edit'])) {
				} else {
					foreach ($rQueueMovies as $rServerID => $rQueueIDs) {
						CoreUtilities::queueMovies($rQueueIDs, $rServerID);
					}
				}

				if (!isset($rData['reprocess_tmdb'])) {
				} else {
					CoreUtilities::refreshMovies($rStreamIDs, 1);
				}
			}

			return array('status' => STATUS_SUCCESS);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processPackage($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'edit_package')) {


					$rArray = overwriteData(getPackage($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'add_packages')) {


					$rArray = verifyPostTable('users_packages', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (strlen($rData['package_name']) != 0) {


				foreach (array('is_trial', 'is_official', 'is_mag', 'is_e2', 'is_line', 'lock_device', 'is_restreamer', 'is_isplock', 'check_compatible') as $rSelection) {
					if (isset($rData[$rSelection])) {
						$rArray[$rSelection] = 1;
					} else {
						$rArray[$rSelection] = 0;
					}
				}
				$rArray['groups'] = '[' . implode(',', array_map('intval', json_decode($rData['groups_selected'], true))) . ']';
				$rArray['bouquets'] = sortArrayByArray(array_values(json_decode($rData['bouquets_selected'], true)), array_keys(getBouquetOrder()));
				$rArray['bouquets'] = '[' . implode(',', array_map('intval', $rArray['bouquets'])) . ']';

				if (!isset($rData['output_formats'])) {
				} else {
					$rArray['output_formats'] = array();

					foreach ($rData['output_formats'] as $rOutput) {
						$rArray['output_formats'][] = $rOutput;
					}
					$rArray['output_formats'] = '[' . implode(',', array_map('intval', $rArray['output_formats'])) . ']';
				}

				$rPrepare = prepareArray($rArray);
				$rQuery = 'REPLACE INTO `users_packages`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (self::$db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = self::$db->last_insert_id();

					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			} else {
				return array('status' => STATUS_INVALID_NAME, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function processMAG($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'edit_mag')) {


					$rArray = overwriteData(getMag($rData['edit']), $rData);
					$rUser = getUser($rArray['user_id']);

					if ($rUser) {
						$rUserArray = overwriteData($rUser, $rData);
					} else {
						$rUserArray = verifyPostTable('lines', $rData);
						$rUserArray['created_at'] = time();
						unset($rUserArray['id']);
					}
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'add_mag')) {


					$rArray = verifyPostTable('mag_devices', $rData);
					$rArray['theme_type'] = CoreUtilities::$rSettings['mag_default_type'];
					$rUserArray = verifyPostTable('lines', $rData);
					$rUserArray['created_at'] = time();
					unset($rArray['mag_id'], $rUserArray['id']);
				} else {
					exit();
				}
			}

			if (strlen($rUserArray['username']) != 0) {
			} else {
				$rUserArray['username'] = generateString(32);
			}

			if (strlen($rUserArray['password']) != 0) {
			} else {
				$rUserArray['password'] = generateString(32);
			}

			if (strlen($rData['isp_clear']) != 0) {
			} else {
				$rUserArray['isp_desc'] = '';
				$rUserArray['as_number'] = null;
			}

			$rUserArray['is_mag'] = 1;
			$rUserArray['is_e2'] = 0;
			$rUserArray['max_connections'] = 1;
			$rUserArray['is_restreamer'] = 0;

			if (isset($rData['is_trial'])) {
				$rUserArray['is_trial'] = 1;
			} else {
				$rUserArray['is_trial'] = 0;
			}

			if (isset($rData['is_isplock'])) {
				$rUserArray['is_isplock'] = 1;
			} else {
				$rUserArray['is_isplock'] = 0;
			}

			if (isset($rData['lock_device'])) {
				$rArray['lock_device'] = 1;
			} else {
				$rArray['lock_device'] = 0;
			}

			$rUserArray['bouquet'] = sortArrayByArray(array_values(json_decode($rData['bouquets_selected'], true)), array_keys(getBouquetOrder()));
			$rUserArray['bouquet'] = '[' . implode(',', array_map('intval', $rUserArray['bouquet'])) . ']';

			if (isset($rData['exp_date']) && !isset($rData['no_expire'])) {
				if (!(0 < strlen($rData['exp_date']) && $rData['exp_date'] != '1970-01-01')) {
				} else {
					try {
						$rDate = new DateTime($rData['exp_date']);
						$rUserArray['exp_date'] = $rDate->format('U');
					} catch (Exception $e) {
						return array('status' => STATUS_INVALID_DATE, 'data' => $rData);
					}
				}
			} else {
				$rUserArray['exp_date'] = null;
			}

			if ($rUserArray['member_id']) {
			} else {
				$rUserArray['member_id'] = self::$rUserInfo['id'];
			}

			if (isset($rData['allowed_ips'])) {
				if (is_array($rData['allowed_ips'])) {
				} else {
					$rData['allowed_ips'] = array($rData['allowed_ips']);
				}

				$rUserArray['allowed_ips'] = json_encode($rData['allowed_ips']);
			} else {
				$rUserArray['allowed_ips'] = '[]';
			}

			if (isset($rData['pair_id'])) {
				$rUserArray['pair_id'] = intval($rData['pair_id']);
			} else {
				$rUserArray['pair_id'] = null;
			}

			$rUserArray['allowed_outputs'] = '[' . implode(',', array(1, 2)) . ']';
			$rDevice = $rArray;
			$rDevice['user'] = $rUserArray;

			if (0 >= $rDevice['user']['pair_id']) {
			} else {
				$rUserCheck = getUser($rDevice['user']['pair_id']);

				if ($rUserCheck) {
				} else {
					return array('status' => STATUS_INVALID_USER, 'data' => $rData);
				}
			}

			if (filter_var($rData['mac'], FILTER_VALIDATE_MAC)) {



				if (isset($rData['edit'])) {
					self::$db->query('SELECT `mag_id` FROM `mag_devices` WHERE mac = ? AND `mag_id` <> ? LIMIT 1;', $rArray['mac'], $rData['edit']);
				} else {
					self::$db->query('SELECT `mag_id` FROM `mag_devices` WHERE mac = ? LIMIT 1;', $rArray['mac']);
				}

				if (0 >= self::$db->num_rows()) {
					$rPrepare = prepareArray($rUserArray);


					$rQuery = 'REPLACE INTO `lines`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
					} else {
						$rInsertID = self::$db->last_insert_id();
						$rArray['user_id'] = $rInsertID;
						CoreUtilities::updateLine($rArray['user_id']);
						unset($rArray['user'], $rArray['paired']);

						if (isset($rData['edit'])) {
						} else {
							$rArray['ver'] = '';
							$rArray['device_id2'] = $rArray['ver'];
							$rArray['device_id'] = $rArray['device_id2'];
							$rArray['hw_version'] = $rArray['device_id'];
							$rArray['stb_type'] = $rArray['hw_version'];
							$rArray['image_version'] = $rArray['stb_type'];
							$rArray['sn'] = $rArray['image_version'];
						}

						$rPrepare = prepareArray($rArray);
						$rQuery = 'REPLACE INTO `mag_devices`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (self::$db->query($rQuery, ...$rPrepare['data'])) {
							$rInsertID = self::$db->last_insert_id();

							if (0 >= $rDevice['user']['pair_id']) {
							} else {
								syncDevices($rDevice['user']['pair_id'], $rInsertID);
								CoreUtilities::updateLine($rDevice['user']['pair_id']);
							}

							return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
						}

						if (isset($rData['edit'])) {
						} else {
							self::$db->query('DELETE FROM `lines` WHERE `id` = ?;', $rInsertID);
						}
					}

					return array('status' => STATUS_FAILURE, 'data' => $rData);
				}

				return array('status' => STATUS_EXISTS_MAC, 'data' => $rData);
			}

			return array('status' => STATUS_INVALID_MAC, 'data' => $rData);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processEnigma($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'edit_e2')) {


					$rArray = overwriteData(getEnigma($rData['edit']), $rData);
					$rUser = getUser($rArray['user_id']);

					if ($rUser) {
						$rUserArray = overwriteData($rUser, $rData);
					} else {
						$rUserArray = verifyPostTable('lines', $rData);
						$rUserArray['created_at'] = time();
						unset($rUserArray['id']);
					}
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'add_e2')) {
					$rArray = verifyPostTable('enigma2_devices', $rData);
					$rUserArray = verifyPostTable('lines', $rData);
					$rUserArray['created_at'] = time();
					unset($rArray['device_id'], $rUserArray['id']);
				} else {
					exit();
				}
			}

			if (strlen($rUserArray['username']) != 0) {
			} else {
				$rUserArray['username'] = generateString(32);
			}

			if (strlen($rUserArray['password']) != 0) {
			} else {
				$rUserArray['password'] = generateString(32);
			}

			if (strlen($rData['isp_clear']) != 0) {
			} else {
				$rUserArray['isp_desc'] = '';
				$rUserArray['as_number'] = null;
			}

			$rUserArray['is_e2'] = 1;
			$rUserArray['is_mag'] = 0;
			$rUserArray['max_connections'] = 1;
			$rUserArray['is_restreamer'] = 0;

			if (isset($rData['is_trial'])) {
				$rUserArray['is_trial'] = 1;
			} else {
				$rUserArray['is_trial'] = 0;
			}

			if (isset($rData['is_isplock'])) {
				$rUserArray['is_isplock'] = 1;
			} else {
				$rUserArray['is_isplock'] = 0;
			}

			if (isset($rData['lock_device'])) {
				$rArray['lock_device'] = 1;
			} else {
				$rArray['lock_device'] = 0;
			}

			$rUserArray['bouquet'] = sortArrayByArray(array_values(json_decode($rData['bouquets_selected'], true)), array_keys(getBouquetOrder()));
			$rUserArray['bouquet'] = '[' . implode(',', array_map('intval', $rUserArray['bouquet'])) . ']';

			if (isset($rData['exp_date']) && !isset($rData['no_expire'])) {
				if (!(0 < strlen($rData['exp_date']) && $rData['exp_date'] != '1970-01-01')) {
				} else {
					try {
						$rDate = new DateTime($rData['exp_date']);
						$rUserArray['exp_date'] = $rDate->format('U');
					} catch (Exception $e) {
						return array('status' => STATUS_INVALID_DATE, 'data' => $rData);
					}
				}
			} else {
				$rUserArray['exp_date'] = null;
			}

			if ($rUserArray['member_id']) {
			} else {
				$rUserArray['member_id'] = self::$rUserInfo['id'];
			}

			if (isset($rData['allowed_ips'])) {
				if (is_array($rData['allowed_ips'])) {
				} else {
					$rData['allowed_ips'] = array($rData['allowed_ips']);
				}

				$rUserArray['allowed_ips'] = json_encode($rData['allowed_ips']);
			} else {
				$rUserArray['allowed_ips'] = '[]';
			}

			if (isset($rData['pair_id'])) {
				$rUserArray['pair_id'] = intval($rData['pair_id']);
			} else {
				$rUserArray['pair_id'] = null;
			}

			$rUserArray['allowed_outputs'] = '[' . implode(',', array(1, 2)) . ']';
			$rDevice = $rArray;
			$rDevice['user'] = $rUserArray;

			if (0 >= $rDevice['user']['pair_id']) {
			} else {
				$rUserCheck = getUser($rDevice['user']['pair_id']);

				if ($rUserCheck) {
				} else {
					return array('status' => STATUS_INVALID_USER, 'data' => $rData);
				}
			}

			if (filter_var($rData['mac'], FILTER_VALIDATE_MAC)) {



				if (isset($rData['edit'])) {
					self::$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE mac = ? AND `device_id` <> ? LIMIT 1;', $rArray['mac'], $rData['edit']);
				} else {
					self::$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE mac = ? LIMIT 1;', $rArray['mac']);
				}

				if (0 >= self::$db->num_rows()) {
					$rPrepare = prepareArray($rUserArray);


					$rQuery = 'REPLACE INTO `lines`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
					} else {
						$rInsertID = self::$db->last_insert_id();
						$rArray['user_id'] = $rInsertID;
						CoreUtilities::updateLine($rArray['user_id']);
						unset($rArray['user'], $rArray['paired']);

						if (isset($rData['edit'])) {
						} else {
							$rArray['token'] = '';
							$rArray['lversion'] = $rArray['token'];
							$rArray['cpu'] = $rArray['lversion'];
							$rArray['enigma_version'] = $rArray['cpu'];
							$rArray['local_ip'] = $rArray['enigma_version'];
							$rArray['modem_mac'] = $rArray['local_ip'];
						}

						$rPrepare = prepareArray($rArray);
						$rQuery = 'REPLACE INTO `enigma2_devices`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (self::$db->query($rQuery, ...$rPrepare['data'])) {
							$rInsertID = self::$db->last_insert_id();

							if (0 >= $rDevice['user']['pair_id']) {
							} else {
								syncDevices($rDevice['user']['pair_id'], $rInsertID);
								CoreUtilities::updateLine($rDevice['user']['pair_id']);
							}

							return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
						}

						if (isset($rData['edit'])) {
						} else {
							self::$db->query('DELETE FROM `lines` WHERE `id` = ?;', $rInsertID);
						}
					}

					return array('status' => STATUS_FAILURE, 'data' => $rData);
				}

				return array('status' => STATUS_EXISTS_MAC, 'data' => $rData);
			}

			return array('status' => STATUS_INVALID_MAC, 'data' => $rData);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processProfile($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rArray = array('profile_name' => $rData['profile_name'], 'profile_options' => null);
			$rProfileOptions = array();

			if ($rData['gpu_device'] != 0) {
				$rProfileOptions['software_decoding'] = (intval($rData['software_decoding']) ?: 0);
				$rProfileOptions['gpu'] = array('val' => $rData['gpu_device'], 'cmd' => '');
				$rProfileOptions['gpu']['device'] = intval(explode('_', $rData['gpu_device'])[1]);

				if (!$rData['software_decoding']) {
					$rCommand = array();
					$rCommand[] = '-hwaccel cuvid';
					$rCommand[] = '-hwaccel_device ' . $rProfileOptions['gpu']['device'];

					if (0 >= strlen($rData['resize'])) {
					} else {
						$rProfileOptions['gpu']['resize'] = $rData['resize'];
						$rCommand[] = '-resize ' . escapeshellcmd($rData['resize']);
					}

					if (0 >= $rData['deint']) {
					} else {
						$rProfileOptions['gpu']['deint'] = intval($rData['deint']);
						$rCommand[] = '-deint ' . intval($rData['deint']);
					}

					$rCodec = '';

					if (0 >= strlen($rData['video_codec_gpu'])) {
					} else {
						$rProfileOptions['-vcodec'] = escapeshellcmd($rData['video_codec_gpu']);
						$rCommand[] = '{INPUT_CODEC}';

						switch ($rData['video_codec_gpu']) {
							case 'hevc_nvenc':
								$rCodec = 'hevc';

								break;

							default:
								$rCodec = 'h264';

								break;
						}
					}

					if (0 >= strlen($rData['preset_' . $rCodec])) {
					} else {
						$rProfileOptions['-preset'] = escapeshellcmd($rData['preset_' . $rCodec]);
					}

					if (0 >= strlen($rData['video_profile_' . $rCodec])) {
					} else {
						$rProfileOptions['-profile:v'] = escapeshellcmd($rData['video_profile_' . $rCodec]);
					}

					$rCommand[] = '-gpu ' . $rProfileOptions['gpu']['device'];
					$rCommand[] = '-drop_second_field 1';
					$rProfileOptions['gpu']['cmd'] = implode(' ', $rCommand);
				} else {
					$rCodec = '';

					if (0 >= strlen($rData['video_codec_gpu'])) {
					} else {
						$rProfileOptions['-vcodec'] = escapeshellcmd($rData['video_codec_gpu']);

						switch ($rData['video_codec_gpu']) {
							case 'hevc_nvenc':
								$rCodec = 'hevc';

								break;
						}
						$rCodec = 'h264';
					}

					if (0 >= strlen($rData['preset_' . $rCodec])) {
					} else {
						$rProfileOptions['-preset'] = escapeshellcmd($rData['preset_' . $rCodec]);
					}

					if (0 >= strlen($rData['video_profile_' . $rCodec])) {
					} else {
						$rProfileOptions['-profile:v'] = escapeshellcmd($rData['video_profile_' . $rCodec]);
					}
				}
			} else {
				if (0 >= strlen($rData['video_codec_cpu'])) {
				} else {
					$rProfileOptions['-vcodec'] = escapeshellcmd($rData['video_codec_cpu']);
				}

				if (0 >= strlen($rData['preset_cpu'])) {
				} else {
					$rProfileOptions['-preset'] = escapeshellcmd($rData['preset_cpu']);
				}

				if (0 >= strlen($rData['video_profile_cpu'])) {
				} else {
					$rProfileOptions['-profile:v'] = escapeshellcmd($rData['video_profile_cpu']);
				}
			}

			if (0 >= strlen($rData['audio_codec'])) {
			} else {
				$rProfileOptions['-acodec'] = escapeshellcmd($rData['audio_codec']);
			}

			if (0 >= strlen($rData['video_bitrate'])) {
			} else {
				$rProfileOptions[3] = array('cmd' => '-b:v ' . intval($rData['video_bitrate']) . 'k', 'val' => intval($rData['video_bitrate']));
			}

			if (0 >= strlen($rData['audio_bitrate'])) {
			} else {
				$rProfileOptions[4] = array('cmd' => '-b:a ' . intval($rData['audio_bitrate']) . 'k', 'val' => intval($rData['audio_bitrate']));
			}

			if (0 >= strlen($rData['min_tolerance'])) {
			} else {
				$rProfileOptions[5] = array('cmd' => '-minrate ' . intval($rData['min_tolerance']) . 'k', 'val' => intval($rData['min_tolerance']));
			}

			if (0 >= strlen($rData['max_tolerance'])) {
			} else {
				$rProfileOptions[6] = array('cmd' => '-maxrate ' . intval($rData['max_tolerance']) . 'k', 'val' => intval($rData['max_tolerance']));
			}

			if (0 >= strlen($rData['buffer_size'])) {
			} else {
				$rProfileOptions[7] = array('cmd' => '-bufsize ' . intval($rData['buffer_size']) . 'k', 'val' => intval($rData['buffer_size']));
			}

			if (0 >= strlen($rData['crf_value'])) {
			} else {
				$rProfileOptions[8] = array('cmd' => '-crf ' . intval($rData['crf_value']), 'val' => $rData['crf_value']);
			}

			if (0 >= strlen($rData['aspect_ratio'])) {
			} else {
				$rProfileOptions[10] = array('cmd' => '-aspect ' . escapeshellcmd($rData['aspect_ratio']), 'val' => $rData['aspect_ratio']);
			}

			if (0 >= strlen($rData['framerate'])) {
			} else {
				$rProfileOptions[11] = array('cmd' => '-r ' . intval($rData['framerate']), 'val' => intval($rData['framerate']));
			}

			if (0 >= strlen($rData['samplerate'])) {
			} else {
				$rProfileOptions[12] = array('cmd' => '-ar ' . intval($rData['samplerate']), 'val' => intval($rData['samplerate']));
			}

			if (0 >= strlen($rData['audio_channels'])) {
			} else {
				$rProfileOptions[13] = array('cmd' => '-ac ' . intval($rData['audio_channels']), 'val' => intval($rData['audio_channels']));
			}

			if (0 >= strlen($rData['threads'])) {
			} else {
				$rProfileOptions[15] = array('cmd' => '-threads ' . intval($rData['threads']), 'val' => intval($rData['threads']));
			}

			$rComplex = false;
			$rScale = $rOverlay = $rLogoInput = '';

			if (0 >= strlen($rData['logo_path'])) {
			} else {
				$rComplex = true;
				$rPos = array_map('intval', explode(':', $rData['logo_pos']));

				if (count($rPos) == 2) {
				} else {
					$rPos = array(10, 10);
				}

				$rLogoInput = '-i ' . escapeshellarg($rData['logo_path']);
				$rProfileOptions[16] = array('cmd' => '', 'val' => $rData['logo_path'], 'pos' => implode(':', $rPos));

				if ($rData['gpu_device'] != 0 && !$rData['software_decoding']) {
					$rOverlay = '[0:v]hwdownload,format=nv12 [base]; [base][1:v] overlay=' . $rPos[0] . ':' . $rPos[1];
				} else {
					$rOverlay = 'overlay=' . $rPos[0] . ':' . $rPos[1];
				}
			}

			if ($rData['gpu_device'] == 0) {
				if (!(isset($rData['yadif_filter']) && 0 < strlen($rData['scaling']))) {
				} else {
					$rComplex = true;
				}

				if ($rComplex) {
					if (isset($rData['yadif_filter']) && 0 < strlen($rData['scaling'])) {
						if (!$rData['software_decoding']) {
							$rScale = '[0:v]yadif,scale=' . escapeshellcmd($rData['scaling']) . '[bg];[bg][1:v]';
						} else {
							$rScale = 'yadif,scale=' . escapeshellcmd($rData['scaling']);
						}

						$rProfileOptions[9] = array('cmd' => '', 'val' => $rData['scaling']);
						$rProfileOptions[17] = array('cmd' => '', 'val' => 1);
					} else {
						if (0 < strlen($rData['scaling'])) {
							$rScale = 'scale=' . escapeshellcmd($rData['scaling']);
							$rProfileOptions[9] = array('cmd' => '', 'val' => $rData['scaling']);
						} else {
							if (!isset($rData['yadif_filter'])) {
							} else {
								if (!$rData['software_decoding']) {
									$rScale = '[0:v]yadif[bg];[bg][1:v]';
								} else {
									$rScale = 'yadif';
								}

								$rProfileOptions[17] = array('cmd' => '', 'val' => 1);
							}
						}
					}
				} else {
					if (0 >= strlen($rData['scaling'])) {
					} else {
						$rProfileOptions[9] = array('cmd' => '-vf scale=' . escapeshellcmd($rData['scaling']), 'val' => $rData['scaling']);
					}

					if (!isset($rData['yadif_filter'])) {
					} else {
						$rProfileOptions[17] = array('cmd' => '-vf yadif', 'val' => 1);
					}
				}
			} else {
				if (!$rData['software_decoding']) {
				} else {
					if (!(0 < intval($rData['deint']) && 0 < strlen($rData['resize']))) {
					} else {
						$rComplex = true;
					}

					if ($rComplex) {
						if (0 < intval($rData['deint']) && 0 < strlen($rData['resize'])) {
							if (!$rData['software_decoding']) {
								$rScale = '[0:v]yadif,scale=' . escapeshellcmd($rData['resize']) . '[bg];[bg][1:v]';
							} else {
								$rScale = 'yadif,scale=' . escapeshellcmd($rData['resize']);
							}

							$rProfileOptions[9] = array('cmd' => '', 'val' => $rData['resize']);
							$rProfileOptions[17] = array('cmd' => '', 'val' => 1);
						} else {
							if (0 < strlen($rData['resize'])) {
								if (!$rData['software_decoding']) {
									$rScale = '[0:v]scale=' . escapeshellcmd($rData['resize']) . '[bg];[bg][1:v]';
								} else {
									$rScale = 'scale=' . escapeshellcmd($rData['resize']);
								}

								$rProfileOptions[9] = array('cmd' => '', 'val' => $rData['resize']);
							} else {
								if (0 >= intval($rData['deint'])) {
								} else {
									if (!$rData['software_decoding']) {
										$rScale = '[0:v]yadif[bg];[bg][1:v]';
									} else {
										$rScale = 'yadif';
									}

									$rProfileOptions[17] = array('cmd' => '', 'val' => 1);
								}
							}
						}
					} else {
						if (0 >= strlen($rData['resize'])) {
						} else {
							$rProfileOptions[9] = array('cmd' => '-vf scale=' . escapeshellcmd($rData['resize']), 'val' => $rData['resize']);
						}

						if (0 >= intval($rData['deint'])) {
						} else {
							$rProfileOptions[17] = array('cmd' => '-vf yadif', 'val' => 1);
						}
					}
				}
			}

			if (!$rComplex) {
			} else {
				if (!empty($rScale) && substr($rScale, strlen($rScale) - 1, 1) != ']') {
					$rOverlay = ',' . $rOverlay;
				} else {
					if (empty($rScale)) {
					} else {
						$rOverlay = ' ' . $rOverlay;
					}
				}

				$rProfileOptions[16]['cmd'] = str_replace(array('{SCALE}', '{OVERLAY}', '{LOGO}'), array($rScale, $rOverlay, $rLogoInput), '{LOGO} -filter_complex "{SCALE}{OVERLAY}"');
			}

			$rArray['profile_options'] = json_encode($rProfileOptions, JSON_UNESCAPED_UNICODE);

			if (!isset($rData['edit'])) {
			} else {
				$rArray['profile_id'] = $rData['edit'];
			}

			$rPrepare = prepareArray($rArray);
			$rQuery = 'REPLACE INTO `profiles`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if (self::$db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = self::$db->last_insert_id();

				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			}

			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processRadio($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'edit_radio')) {


					$rArray = overwriteData(getStream($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'add_radio')) {


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

					foreach (json_decode($rData['bouquet_create_list'], true) as $rBouquet) {
						$rPrepare = prepareArray(array('bouquet_name' => $rBouquet, 'bouquet_channels' => array(), 'bouquet_movies' => array(), 'bouquet_series' => array(), 'bouquet_radios' => array()));
						$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rBouquetID = self::$db->last_insert_id();
							$rBouquetCreate[$rBouquet] = $rBouquetID;
						}
					}
					$rCategoryCreate = array();

					foreach (json_decode($rData['category_create_list'], true) as $rCategory) {
						$rPrepare = prepareArray(array('category_type' => 'radio', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
						$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rCategoryID = self::$db->last_insert_id();
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
						$rImportArray = $rArray;

						if (!self::$rSettings['download_images']) {
						} else {
							$rImportStream['stream_icon'] = CoreUtilities::downloadImage($rImportStream['stream_icon'], 4);
						}

						foreach (array_keys($rImportStream) as $rKey) {
							$rImportArray[$rKey] = $rImportStream[$rKey];
						}

						if (isset($rData['edit'])) {
						} else {
							$rImportArray['order'] = getNextOrder();
						}

						$rPrepare = prepareArray($rImportArray);
						$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (self::$db->query($rQuery, ...$rPrepare['data'])) {
							$rInsertID = self::$db->last_insert_id();
							$rStationExists = array();

							if (!isset($rData['edit'])) {
							} else {
								self::$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);

								foreach (self::$db->get_rows() as $rRow) {
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
									$rOD = intval(in_array($rServerID, ($rData['on_demand'] ?: array())));

									if ($rServer['parent'] == 'source') {
										$rParent = null;
									} else {
										$rParent = intval($rServer['parent']);
									}

									if (isset($rStationExists[$rServerID])) {
										self::$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStationExists[$rServerID]);
									} else {
										self::$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES(?, ?, ?, ?);', $rInsertID, $rServerID, $rParent, $rOD);
									}
								}
							}

							foreach ($rStationExists as $rServerID => $rDBID) {
								if (in_array($rServerID, $rStreamsAdded)) {
								} else {
									deleteStream($rInsertID, $rServerID, false, false);
								}
							}
							self::$db->query('DELETE FROM `streams_options` WHERE `stream_id` = ?;', $rInsertID);

							if (!(isset($rData['user_agent']) && 0 < strlen($rData['user_agent']))) {
							} else {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 1, ?);', $rInsertID, $rData['user_agent']);
							}

							if (!(isset($rData['http_proxy']) && 0 < strlen($rData['http_proxy']))) {
							} else {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 2, ?);', $rInsertID, $rData['http_proxy']);
							}

							if (!(isset($rData['cookie']) && 0 < strlen($rData['cookie']))) {
							} else {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 17, ?);', $rInsertID, $rData['cookie']);
							}

							if (!(isset($rData['headers']) && 0 < strlen($rData['headers']))) {
							} else {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 19, ?);', $rInsertID, $rData['headers']);
							}

							if (isset($rData['skip_ffprobe']) && ($rData['skip_ffprobe'] == 'on' || $rData['skip_ffprobe'] == 1)) {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 21, ?);', $rInsertID, '1');
							}

							if (isset($rData['force_input_acodec']) && strlen(trim($rData['force_input_acodec'])) > 0) {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 20, ?);', $rInsertID, trim($rData['force_input_acodec']));
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
								foreach (getBouquets() as $rBouquet) {
									if (in_array($rBouquet['id'], $rBouquets)) {
									} else {
										removeFromBouquet('radio', $rBouquet['id'], $rInsertID);
									}
								}
							}

							CoreUtilities::updateStream($rInsertID);

							return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
						} else {
							foreach ($rBouquetCreate as $rBouquet => $rID) {
								self::$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
							}

							foreach ($rCategoryCreate as $rCategory => $rID) {
								self::$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
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

	public static function massEditRadios($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {
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
					self::$db->query('SELECT `id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

					foreach (self::$db->get_rows() as $rRow) {
						$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: array());
					}
				}

				$rDeleteServers = $rStreamExists = array();
				self::$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

				foreach (self::$db->get_rows() as $rRow) {
					$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
				}
				$rBouquets = getBouquets();
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
						self::$db->query($rQuery, ...$rPrepare['data']);
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
										self::$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rStreamID][$rServerID]);
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
					self::$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES ' . $rAddQuery . ';');
				}

				CoreUtilities::updateStreams($rStreamIDs);

				if (!isset($rData['restart_on_edit'])) {
				} else {
					APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => array_values($rStreamIDs)));
				}
			}

			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processUser($rData, $rBypassAuth = false) {
		if (self::checkMinimumRequirements($rData)) {



			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'edit_reguser') || $rBypassAuth) {
					$rUser = getRegisteredUser($rData['edit']);


					$rArray = overwriteData($rUser, $rData, array('password'));
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'add_reguser') || $rBypassAuth) {
					$rArray = verifyPostTable('users', $rData);
					$rArray['date_registered'] = time();
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (!empty($rData['member_group_id'])) {
				if (strlen($rData['username']) == 0) {
					$rArray['username'] = generateString(10);
				}

				if (!checkExists('users', 'username', $rArray['username'], 'id', $rData['edit'])) {
					if (strlen($rData['password']) > 0) {
						$rArray['password'] = cryptPassword($rData['password']);
					}

					$rOverride = array();

					foreach ($rData as $rKey => $rCredits) {
						if (substr($rKey, 0, 9) == 'override_') {
							$rID = intval(explode('override_', $rKey)[1]);

							if (0 < strlen($rCredits)) {
								$rCredits = intval($rCredits);
							} else {
								$rCredits = null;
							}

							if ($rCredits) {
								$rOverride[$rID] = array('assign' => 1, 'official_credits' => $rCredits);
							}
						}
					}

					if (ctype_xdigit($rArray['api_key']) && strlen($rArray['api_key']) == 32) {
					} else {
						$rArray['api_key'] = '';
					}

					$rArray['override_packages'] = json_encode($rOverride);

					if (!(isset($rUser) && $rUser['credits'] != $rData['credits'])) {
					} else {
						$rCreditsAdjustment = $rData['credits'] - $rUser['credits'];
						$rReason = $rData['credits_reason'];
					}

					$rPrepare = prepareArray($rArray);
					$rQuery = 'REPLACE INTO `users`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (self::$db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = self::$db->last_insert_id();

						if (!isset($rCreditsAdjustment)) {
						} else {
							self::$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rInsertID, self::$rUserInfo['id'], $rCreditsAdjustment, time(), $rReason);
						}

						return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
					}

					return array('status' => STATUS_FAILURE, 'data' => $rData);
				} else {
					return array('status' => STATUS_EXISTS_USERNAME, 'data' => $rData);
				}
			} else {
				return array('status' => STATUS_INVALID_GROUP, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function processRTMPIP($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				$rArray = overwriteData(getRTMPIP($rData['edit']), $rData);
			} else {
				$rArray = verifyPostTable('rtmp_ips', $rData);
				unset($rArray['id']);
			}

			foreach (array('push', 'pull') as $rSelection) {
				if (isset($rData[$rSelection])) {
					$rArray[$rSelection] = 1;
				} else {
					$rArray[$rSelection] = 0;
				}
			}

			if (filter_var($rData['ip'], FILTER_VALIDATE_IP)) {


				if (!checkExists('rtmp_ips', 'ip', $rData['ip'], 'id', $rArray['id'])) {


					if (strlen($rData['password']) != 0) {
					} else {
						$rArray['password'] = generateString(16);
					}

					$rPrepare = prepareArray($rArray);
					$rQuery = 'REPLACE INTO `rtmp_ips`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (self::$db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = self::$db->last_insert_id();

						return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
					}

					return array('status' => STATUS_FAILURE, 'data' => $rData);
				}

				return array('status' => STATUS_EXISTS_IP, 'data' => $rData);
			}

			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function importSeries($rData) {
		if (hasPermissions('adv', 'import_movies')) {
			if (self::checkMinimumRequirements($rData)) {
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
				self::$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 5;');

				foreach (self::$db->get_rows() as $rRow) {
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

						if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rBouquets[] = self::$db->last_insert_id();
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

						if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rCategories[] = self::$db->last_insert_id();
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
					$rWatchCategories = array(1 => getWatchCategories(1), 2 => getWatchCategories(2));

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

	public static function importMovies($rData) {
		if (hasPermissions('adv', 'import_movies')) {


			if (self::checkMinimumRequirements($rData)) {


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
				self::$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 2;');

				foreach (self::$db->get_rows() as $rRow) {
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

						if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rBouquets[] = self::$db->last_insert_id();
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

						if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rCategories[] = self::$db->last_insert_id();
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
					$rWatchCategories = array(1 => getWatchCategories(1), 2 => getWatchCategories(2));

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

	public static function processSeries($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'edit_series')) {


					$rArray = overwriteData(getSerie($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'add_series')) {


					$rArray = verifyPostTable('streams_series', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (!self::$rSettings['download_images']) {
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

				if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
				} else {
					$rBouquetID = self::$db->last_insert_id();
					$rBouquetCreate[$rBouquet] = $rBouquetID;
				}
			}
			$rCategoryCreate = array();

			foreach (json_decode($rData['category_create_list'], true) as $rCategory) {
				$rPrepare = prepareArray(array('category_type' => 'series', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
				$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
				} else {
					$rCategoryID = self::$db->last_insert_id();
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

			if (self::$db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = self::$db->last_insert_id();
				updateSeriesAsync($rInsertID);

				foreach ($rBouquets as $rBouquet) {
					addToBouquet('series', $rBouquet, $rInsertID);
				}

				foreach (getBouquets() as $rBouquet) {
					if (in_array($rBouquet['id'], $rBouquets)) {
					} else {
						removeFromBouquet('series', $rBouquet['id'], $rInsertID);
					}
				}

				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			} else {
				foreach ($rBouquetCreate as $rBouquet => $rID) {
					self::$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
				}

				foreach ($rCategoryCreate as $rCategory => $rID) {
					self::$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function massEditSeries($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {
			$rArray = array();


			$rSeriesIDs = json_decode($rData['series'], true);

			if (0 >= count($rSeriesIDs)) {
			} else {
				$rCategoryMap = array();

				if (!(isset($rData['c_category_id']) && in_array($rData['category_id_type'], array('ADD', 'DEL')))) {
				} else {
					self::$db->query('SELECT `id`, `category_id` FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rSeriesIDs)) . ');');

					foreach (self::$db->get_rows() as $rRow) {
						$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: array());
					}
				}

				$rBouquets = getBouquets();
				$rAddBouquet = $rDelBouquet = array();

				foreach ($rSeriesIDs as $rSeriesID) {
					if (!isset($rData['c_category_id'])) {
					} else {
						$rCategories = array_map('intval', $rData['category_id']);

						if ($rData['category_id_type'] == 'ADD') {
							foreach (($rCategoryMap[$rSeriesID] ?: array()) as $rCategoryID) {
								if (in_array($rCategoryID, $rCategories)) {
								} else {
									$rCategories[] = $rCategoryID;
								}
							}
						} else {
							if ($rData['category_id_type'] != 'DEL') {
							} else {
								$rNewCategories = $rCategoryMap[$rSeriesID];

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
						$rPrepare['data'][] = $rSeriesID;
						$rQuery = 'UPDATE `streams_series` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
						self::$db->query($rQuery, ...$rPrepare['data']);
					}

					if (!isset($rData['c_bouquets'])) {
					} else {
						if ($rData['bouquets_type'] == 'SET') {
							foreach ($rData['bouquets'] as $rBouquet) {
								$rAddBouquet[$rBouquet][] = $rSeriesID;
							}

							foreach ($rBouquets as $rBouquet) {
								if (in_array($rBouquet['id'], $rData['bouquets'])) {
								} else {
									$rDelBouquet[$rBouquet['id']][] = $rSeriesID;
								}
							}
						} else {
							if ($rData['bouquets_type'] == 'ADD') {
								foreach ($rData['bouquets'] as $rBouquet) {
									$rAddBouquet[$rBouquet][] = $rSeriesID;
								}
							} else {
								if ($rData['bouquets_type'] != 'DEL') {
								} else {
									foreach ($rData['bouquets'] as $rBouquet) {
										$rDelBouquet[$rBouquet][] = $rSeriesID;
									}
								}
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

				if (!isset($rData['reprocess_tmdb'])) {
				} else {
					foreach ($rSeriesIDs as $rSeriesID) {
						if (0 >= intval($rSeriesID)) {
						} else {
							self::$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(2, ?, 0);', $rSeriesID);
						}
					}
				}
			}

			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processServer($rData) {
		if (hasPermissions('adv', 'edit_server')) {
			if (self::checkMinimumRequirements($rData)) {
				$rServer = getStreamingServersByID($rData['edit']);
				if ($rServer) {
					$rArray = verifyPostTable('servers', $rData, true);
					$rPorts = array('http' => array(), 'https' => array());

					foreach ($rData['http_broadcast_ports'] as $rPort) {
						if (!(is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535 && !in_array($rPort, ($rPorts['http'] ?: array())) && $rPort != $rData['rtmp_port'])) {
						} else {
							$rPorts['http'][] = $rPort;
						}
					}
					$rPorts['http'] = array_unique($rPorts['http']);
					unset($rData['http_broadcast_ports']);

					foreach ($rData['https_broadcast_ports'] as $rPort) {
						if (!(is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535 && !in_array($rPort, ($rPorts['http'] ?: array())) && !in_array($rPort, ($rPorts['https'] ?: array())) && $rPort != $rData['rtmp_port'])) {
						} else {
							$rPorts['https'][] = $rPort;
						}
					}
					$rPorts['https'] = array_unique($rPorts['https']);
					unset($rData['https_broadcast_ports']);
					$rArray['http_broadcast_port'] = null;
					$rArray['http_ports_add'] = null;

					if (count($rPorts['http']) > 0) {
						$rArray['http_broadcast_port'] = $rPorts['http'][0];

						if (1 >= count($rPorts['http'])) {
						} else {
							$rArray['http_ports_add'] = implode(',', array_slice($rPorts['http'], 1, count($rPorts['http']) - 1));
						}
					}

					$rArray['https_broadcast_port'] = null;
					$rArray['https_ports_add'] = null;

					if (0 >= count($rPorts['https'])) {
					} else {
						$rArray['https_broadcast_port'] = $rPorts['https'][0];

						if (1 >= count($rPorts['https'])) {
						} else {
							$rArray['https_ports_add'] = implode(',', array_slice($rPorts['https'], 1, count($rPorts['https']) - 1));
						}
					}

					foreach (array('enable_gzip', 'timeshift_only', 'enable_https', 'random_ip', 'enable_geoip', 'enable_isp', 'enabled', 'enable_proxy') as $rKey) {
						if (isset($rData[$rKey])) {
							$rArray[$rKey] = 1;
						} else {
							$rArray[$rKey] = 0;
						}
					}

					if ($rServer['is_main']) {
						$rArray['enabled'] = 1;
					}

					if (isset($rData['geoip_countries'])) {
						$rArray['geoip_countries'] = array();

						foreach ($rData['geoip_countries'] as $rCountry) {
							$rArray['geoip_countries'][] = $rCountry;
						}
					} else {
						$rArray['geoip_countries'] = array();
					}

					if (isset($rData['isp_names'])) {
						$rArray['isp_names'] = array();

						foreach ($rData['isp_names'] as $rISP) {
							$rArray['isp_names'][] = strtolower(trim(preg_replace('/[^A-Za-z0-9 ]/', '', $rISP)));
						}
					} else {
						$rArray['isp_names'] = array();
					}

					if (isset($rData['domain_name'])) {
						$rArray['domain_name'] = implode(',', $rData['domain_name']);
					} else {
						$rArray['domain_name'] = '';
					}

					if (strlen($rData['server_ip']) != 0 && filter_var($rData['server_ip'], FILTER_VALIDATE_IP)) {
						if (0 >= strlen($rData['private_ip']) || filter_var($rData['private_ip'], FILTER_VALIDATE_IP)) {
							$rArray['total_services'] = $rData['total_services'];
							$rPrepare = prepareArray($rArray);
							$rPrepare['data'][] = $rData['edit'];
							$rQuery = 'UPDATE `servers` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';

							if (self::$db->query($rQuery, ...$rPrepare['data'])) {
								$rInsertID = $rData['edit'];
								$rPorts = array('http' => array(), 'https' => array());

								foreach (array_merge(array(intval($rArray['http_broadcast_port'])), explode(',', $rArray['http_ports_add'])) as $rPort) {
									if (is_numeric($rPort) && 0 < $rPort && $rPort <= 65535) {
										$rPorts['http'][] = intval($rPort);
									}
								}

								foreach (array_merge(array(intval($rArray['https_broadcast_port'])), explode(',', $rArray['https_ports_add'])) as $rPort) {
									if (is_numeric($rPort) && 0 < $rPort && $rPort <= 65535) {
										$rPorts['https'][] = intval($rPort);
									}
								}
								changePort($rInsertID, 0, $rPorts['http'], false);
								changePort($rInsertID, 1, $rPorts['https'], false);
								changePort($rInsertID, 2, array($rArray['rtmp_port']), false);
								setServices($rInsertID, intval($rArray['total_services']), true);

								if (empty($rArray['governor'])) {
								} else {
									setGovernor($rInsertID, $rArray['governor']);
								}

								if (!empty($rArray['sysctl'])) {
									setSysctl($rInsertID, $rArray['sysctl']);
								}

								if (file_exists(CACHE_TMP_PATH . 'servers')) {
									unlink(CACHE_TMP_PATH . 'servers');
								}

								$rFS = getFreeSpace($rInsertID);
								$rMounted = false;

								foreach ($rFS as $rMount) {
									if ($rMount['mount'] != rtrim(STREAMS_PATH, '/')) {
									} else {
										$rMounted = true;

										break;
									}
								}

								if ($rData['disable_ramdisk'] && $rMounted) {
									self::$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rInsertID, time(), json_encode(array('action' => 'disable_ramdisk')));
								} else {
									if ($rData['disable_ramdisk'] || $rMounted) {
									} else {
										self::$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rInsertID, time(), json_encode(array('action' => 'enable_ramdisk')));
									}
								}
								return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
							} else {
								return array('status' => STATUS_FAILURE, 'data' => $rData);
							}
						} else {
							return array('status' => STATUS_INVALID_IP, 'data' => $rData);
						}
					} else {
						return array('status' => STATUS_INVALID_IP, 'data' => $rData);
					}
				} else {
					return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
				}
			} else {
				return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
			}
		} else {
			exit();
		}
	}

	public static function processProxy($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (hasPermissions('adv', 'edit_server')) {
				$rArray = overwriteData(getStreamingServersByID($rData['edit']), $rData);


				foreach (array('enable_https', 'random_ip', 'enable_geoip', 'enabled') as $rKey) {
					if (isset($rData[$rKey])) {
						$rArray[$rKey] = true;
					} else {
						$rArray[$rKey] = false;
					}
				}

				if (isset($rData['geoip_countries'])) {
					$rArray['geoip_countries'] = array();

					foreach ($rData['geoip_countries'] as $rCountry) {
						$rArray['geoip_countries'][] = $rCountry;
					}
				} else {
					$rArray['geoip_countries'] = array();
				}

				if (isset($rData['domain_name'])) {
					$rArray['domain_name'] = implode(',', $rData['domain_name']);
				} else {
					$rArray['domain_name'] = '';
				}

				if (strlen($rData['server_ip']) != 0 && filter_var($rData['server_ip'], FILTER_VALIDATE_IP)) {


					if (!checkExists('servers', 'server_ip', $rData['server_ip'], 'id', $rArray['id'])) {


						$rArray['server_type'] = 1;
						$rPrepare = prepareArray($rArray);
						$rQuery = 'REPLACE INTO `servers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (self::$db->query($rQuery, ...$rPrepare['data'])) {
							$rInsertID = self::$db->last_insert_id();

							if (!file_exists(CACHE_TMP_PATH . 'servers')) {
							} else {
								unlink(CACHE_TMP_PATH . 'servers');
							}

							if (!file_exists(CACHE_TMP_PATH . 'proxy_servers')) {
							} else {
								unlink(CACHE_TMP_PATH . 'proxy_servers');
							}

							return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
						}

						return array('status' => STATUS_FAILURE, 'data' => $rData);
					}

					return array('status' => STATUS_EXISTS_IP, 'data' => $rData);
				}

				return array('status' => STATUS_INVALID_IP, 'data' => $rData);
			} else {
				exit();
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function installServer($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (hasPermissions('adv', 'add_server')) {
				$rParentIDs = array();

				if (isset($rData['update_sysctl'])) {
					$rUpdateSysctl = 1;
				} else {
					$rUpdateSysctl = 0;
				}

				if (isset($rData['use_private_ip'])) {
					$rPrivateIP = 1;
				} else {
					$rPrivateIP = 0;
				}

				if ($rData['type'] == 1) {
					foreach (json_decode($rData['parent_id'], true) as $rServerID) {
						if (self::$rServers[$rServerID]['server_type'] == 0) {
							$rParentIDs[] = intval($rServerID);
						}
					}
				}

				if (isset($rData['edit'])) {
					if ($rData['type'] == 1) {
						$rServer = self::$rProxyServers[$rData['edit']];
					} else {
						$rServer = self::$rServers[$rData['edit']];
					}

					if (!$rServer) {
						return array('status' => STATUS_FAILURE, 'data' => $rData);
					}

					self::$db->query('UPDATE `servers` SET `status` = 3, `parent_id` = ? WHERE `id` = ?;', '[' . implode(',', $rParentIDs) . ']', $rServer['id']);

					if ($rData['type'] == 1) {
						$rCommand = PHP_BIN . ' ' . CLI_PATH . 'balancer.php ' . intval($rData['type']) . ' ' . intval($rServer['id']) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' ' . intval($rData['http_broadcast_port']) . ' ' . intval($rData['https_broadcast_port']) . ' ' . intval($rUpdateSysctl) . ' ' . intval($rPrivateIP) . ' "' . json_encode($rParentIDs) . '" > "' . BIN_PATH . 'install/' . intval($rServer['id']) . '.install" 2>/dev/null &';
					} else {
						$rCommand = PHP_BIN . ' ' . CLI_PATH . 'balancer.php ' . intval($rData['type']) . ' ' . intval($rServer['id']) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' 80 443 ' . intval($rUpdateSysctl) . ' > "' . BIN_PATH . 'install/' . intval($rServer['id']) . '.install" 2>/dev/null &';
					}

					shell_exec($rCommand);

					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rServer['id']));
				}

				$rArray = verifyPostTable('servers', $rData);
				$rArray['status'] = 3;
				unset($rArray['id']);

				if (strlen($rArray['server_ip']) != 0 && filter_var($rArray['server_ip'], FILTER_VALIDATE_IP)) {

					if ($rData['type'] == 1) {
						$rArray['server_type'] = 1;
						$rArray['parent_id'] = '[' . implode(',', $rParentIDs) . ']';
					} else {
						$rArray['server_type'] = 0;
					}

					$rArray['network_interface'] = 'auto';
					$rPrepare = prepareArray($rArray);
					$rQuery = 'INSERT INTO `servers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (self::$db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = self::$db->last_insert_id();

						if ($rArray['server_type'] == 0) {
							CoreUtilities::grantPrivileges($rArray['server_ip']);
						}

						if ($rData['type'] == 1) {
							$rCommand = PHP_BIN . ' ' . CLI_PATH . 'balancer.php ' . intval($rData['type']) . ' ' . intval($rInsertID) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' ' . intval($rData['http_broadcast_port']) . ' ' . intval($rData['https_broadcast_port']) . ' ' . intval($rUpdateSysctl) . ' ' . intval($rPrivateIP) . ' "' . json_encode($rParentIDs) . '" > "' . BIN_PATH . 'install/' . intval($rInsertID) . '.install" 2>/dev/null &';
						} else {
							$rCommand = PHP_BIN . ' ' . CLI_PATH . 'balancer.php ' . intval($rData['type']) . ' ' . intval($rInsertID) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' 80 443 ' . intval($rUpdateSysctl) . ' > "' . BIN_PATH . 'install/' . intval($rInsertID) . '.install" 2>/dev/null &';
						}

						shell_exec($rCommand);

						return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
					}

					return array('status' => STATUS_FAILURE, 'data' => $rData);
				}

				return array('status' => STATUS_INVALID_IP, 'data' => $rData);
			}

			exit();
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function editSettings($rData) {
		if (self::checkMinimumRequirements($rData)) {
			foreach (array('user_agent', 'http_proxy', 'cookie', 'headers') as $rKey) {
				self::$db->query('UPDATE `streams_arguments` SET `argument_default_value` = ? WHERE `argument_key` = ?;', ($rData[$rKey] ?: null), $rKey);
				unset($rData[$rKey]);
			}
			$rArray = verifyPostTable('settings', $rData, true);

			foreach (array('php_loopback', 'restreamer_bypass_proxy', 'request_prebuffer', 'modal_edit', 'group_buttons', 'enable_search', 'on_demand_checker', 'ondemand_balance_equal', 'disable_mag_token', 'allow_cdn_access', 'dts_legacy_ffmpeg', 'mag_load_all_channels', 'disable_xmltv_restreamer', 'disable_playlist_restreamer', 'ffmpeg_warnings', 'reseller_ssl_domain', 'extract_subtitles', 'show_category_duplicates', 'vod_sort_newest', 'header_stats', 'mag_keep_extension', 'keep_protocol', 'read_native_hls', 'player_allow_playlist', 'player_allow_bouquet', 'player_hide_incompatible', 'player_allow_hevc', 'force_epg_timezone', 'check_vod', 'ignore_keyframes', 'save_login_logs', 'save_restart_logs', 'mag_legacy_redirect', 'restrict_playlists', 'monitor_connection_status', 'kill_rogue_ffmpeg', 'show_images', 'on_demand_instant_off', 'on_demand_failure_exit', 'playlist_from_mysql', 'ignore_invalid_users', 'legacy_mag_auth', 'ministra_allow_blank', 'block_proxies', 'block_streaming_servers', 'ip_subnet_match', 'debug_show_errors', 'enable_debug_stalker', 'restart_php_fpm', 'restream_deny_unauthorised', 'api_probe', 'legacy_panel_api', 'hide_failures', 'verify_host', 'encrypt_playlist', 'encrypt_playlist_restreamer', 'mag_disable_ssl', 'legacy_get', 'legacy_xmltv', 'save_closed_connection', 'show_tickets', 'stream_logs_save', 'client_logs_save', 'streams_grouped', 'cloudflare', 'cleanup', 'dashboard_stats', 'dashboard_status', 'dashboard_map', 'dashboard_display_alt', 'recaptcha_enable', 'ip_logout', 'disable_player_api', 'disable_playlist', 'disable_xmltv', 'disable_enigma2', 'disable_ministra', 'enable_isp_lock', 'block_svp', 'disable_ts', 'disable_ts_allow_restream', 'disable_hls', 'disable_hls_allow_restream', 'disable_rtmp', 'disable_rtmp_allow_restream', 'case_sensitive_line', 'county_override_1st', 'disallow_2nd_ip_con', 'use_mdomain_in_lists', 'encrypt_hls', 'disallow_empty_user_agents', 'detect_restream_block_user', 'download_images', 'api_redirect', 'use_buffer', 'audio_restart_loss', 'show_isps', 'priority_backup', 'rtmp_random', 'show_connected_video', 'show_not_on_air_video', 'show_banned_video', 'show_expired_video', 'show_expiring_video', 'show_all_category_mag', 'always_enabled_subtitles', 'enable_connection_problem_indication', 'show_tv_channel_logo', 'show_channel_logo_in_preview', 'disable_trial', 'restrict_same_ip', 'js_navigate') as $rSetting) {
				if (isset($rData[$rSetting])) {
					$rArray[$rSetting] = 1;
				} else {
					$rArray[$rSetting] = 0;
				}
			}

			if (isset($rData['allowed_stb_types_for_local_recording'])) {
			} else {
				$rArray['allowed_stb_types_for_local_recording'] = array();
			}

			if (isset($rData['allowed_stb_types'])) {
			} else {
				$rArray['allowed_stb_types'] = array();
			}

			if (isset($rData['allow_countries'])) {
			} else {
				$rArray['allow_countries'] = array('ALL');
			}

			if ($rArray['mag_legacy_redirect']) {
				if (!file_exists(MAIN_HOME . 'www/c/')) {
					self::$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', SERVER_ID, time(), json_encode(array('action' => 'enable_ministra')));
				}
			} else {
				if (file_exists(MAIN_HOME . 'www/c/')) {
					self::$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', SERVER_ID, time(), json_encode(array('action' => 'disable_ministra')));
				}
			}

			if (100 >= $rArray['search_items']) {
			} else {
				$rArray['search_items'] = 100;
			}

			if ($rArray['search_items'] > 0) {
			} else {
				$rArray['search_items'] = 1;
			}

			$rPrepare = prepareArray($rArray);

			if (0 >= count($rPrepare['data'])) {
			} else {
				$rQuery = 'UPDATE `settings` SET ' . $rPrepare['update'] . ';';

				if (self::$db->query($rQuery, ...$rPrepare['data'])) {
					clearSettingsCache();

					return array('status' => STATUS_SUCCESS);
				}

				return array('status' => STATUS_FAILURE);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function editBackupSettings($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rArray = verifyPostTable('settings', $rData, true);

			foreach (array('dropbox_remote') as $rSetting) {
				if (isset($rData[$rSetting])) {
					$rArray[$rSetting] = 1;
				} else {
					$rArray[$rSetting] = 0;
				}
			}

			if (isset($rData['allowed_stb_types_for_local_recording'])) {
			} else {
				$rArray['allowed_stb_types_for_local_recording'] = array();
			}

			if (isset($rData['allowed_stb_types'])) {
			} else {
				$rArray['allowed_stb_types'] = array();
			}

			$rPrepare = prepareArray($rArray);

			if (0 >= count($rPrepare['data'])) {
			} else {
				$rQuery = 'UPDATE `settings` SET ' . $rPrepare['update'] . ';';

				if (self::$db->query($rQuery, ...$rPrepare['data'])) {
					clearSettingsCache();

					return array('status' => STATUS_SUCCESS);
				}

				return array('status' => STATUS_FAILURE);
			}
		} else {



			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function editCacheCron($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rCheck = array(false, false);
			$rCron = array('*', '*', '*', '*', '*');
			$rPattern = '/^[0-9\\/*,-]+$/';
			$rCron[0] = $rData['minute'];
			preg_match($rPattern, $rCron[0], $rMatches);
			$rCheck[0] = 0 < count($rMatches);
			$rCron[1] = $rData['hour'];
			preg_match($rPattern, $rCron[1], $rMatches);
			$rCheck[1] = 0 < count($rMatches);
			$rCronOutput = implode(' ', $rCron);

			if (isset($rData['cache_changes'])) {
				$rCacheChanges = true;
			} else {
				$rCacheChanges = false;
			}

			if ($rCheck[0] && $rCheck[1]) {
				self::$db->query("UPDATE `crontab` SET `time` = ? WHERE `filename` = 'cache_engine.php';", $rCronOutput);
				self::$db->query('UPDATE `settings` SET `cache_thread_count` = ?, `cache_changes` = ?;', $rData['cache_thread_count'], $rCacheChanges);

				if (!file_exists(TMP_PATH . 'crontab')) {
				} else {
					unlink(TMP_PATH . 'crontab');
				}

				clearSettingsCache();

				return array('status' => STATUS_SUCCESS);
			}

			return array('status' => STATUS_FAILURE);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function editPlexSettings($rData) {
		if (self::checkMinimumRequirements($rData)) {
			foreach ($rData as $rKey => $rValue) {
				$rSplit = explode('_', $rKey);

				if ($rSplit[0] != 'genre') {
				} else {
					if (isset($rData['bouquet_' . $rSplit[1]])) {
						$rBouquets = '[' . implode(',', array_map('intval', $rData['bouquet_' . $rSplit[1]])) . ']';
					} else {
						$rBouquets = '[]';
					}

					self::$db->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = 3;', $rValue, $rBouquets, $rSplit[1]);
				}
			}

			foreach ($rData as $rKey => $rValue) {
				$rSplit = explode('_', $rKey);

				if ($rSplit[0] != 'genretv') {
				} else {
					if (isset($rData['bouquettv_' . $rSplit[1]])) {
						$rBouquets = '[' . implode(',', array_map('intval', $rData['bouquettv_' . $rSplit[1]])) . ']';
					} else {
						$rBouquets = '[]';
					}

					self::$db->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = 4;', $rValue, $rBouquets, $rSplit[1]);
				}
			}
			self::$db->query('UPDATE `settings` SET `scan_seconds` = ?, `max_genres` = ?, `thread_count_movie` = ?, `thread_count_show` = ?;', $rData['scan_seconds'], $rData['max_genres'], $rData['thread_count_movie'], $rData['thread_count_show']);
			clearSettingsCache();

			return array('status' => STATUS_SUCCESS);
		} else {



			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function editWatchSettings($rData) {
		if (self::checkMinimumRequirements($rData)) {
			foreach ($rData as $rKey => $rValue) {
				$rSplit = explode('_', $rKey);

				if ($rSplit[0] != 'genre') {
				} else {
					if (isset($rData['bouquet_' . $rSplit[1]])) {
						$rBouquets = '[' . implode(',', array_map('intval', $rData['bouquet_' . $rSplit[1]])) . ']';
					} else {
						$rBouquets = '[]';
					}

					self::$db->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = 1;', $rValue, $rBouquets, $rSplit[1]);
				}
			}

			foreach ($rData as $rKey => $rValue) {
				$rSplit = explode('_', $rKey);

				if ($rSplit[0] != 'genretv') {
				} else {
					if (isset($rData['bouquettv_' . $rSplit[1]])) {
						$rBouquets = '[' . implode(',', array_map('intval', $rData['bouquettv_' . $rSplit[1]])) . ']';
					} else {
						$rBouquets = '[]';
					}

					self::$db->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = 2;', $rValue, $rBouquets, $rSplit[1]);
				}
			}

			if (isset($rData['alternative_titles'])) {
				$rAltTitles = true;
			} else {
				$rAltTitles = false;
			}

			if (isset($rData['fallback_parser'])) {
				$rFallbackParser = true;
			} else {
				$rFallbackParser = false;
			}

			self::$db->query('UPDATE `settings` SET `percentage_match` = ?, `scan_seconds` = ?, `thread_count` = ?, `max_genres` = ?, `max_items` = ?, `alternative_titles` = ?, `fallback_parser` = ?;', $rData['percentage_match'], $rData['scan_seconds'], $rData['thread_count'], $rData['max_genres'], $rData['max_items'], $rAltTitles, $rFallbackParser);
			clearSettingsCache();

			return array('status' => STATUS_SUCCESS);
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function massEditStreams($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {
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
					self::$db->query('SELECT `id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

					foreach (self::$db->get_rows() as $rRow) {
						$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: array());
					}
				}

				$rDeleteServers = $rStreamExists = array();
				self::$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

				foreach (self::$db->get_rows() as $rRow) {
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
						self::$db->query($rQuery, ...$rPrepare['data']);
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
										self::$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rStreamID][$rServerID]);
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
							$rOptQuery .= '(' . intval($rStreamID) . ', 1, ' . self::$db->escape($rData['user_agent']) . '),';
						}
					}

					if (!isset($rData['c_http_proxy'])) {
					} else {
						if (!(isset($rData['http_proxy']) && 0 < strlen($rData['http_proxy']))) {
						} else {
							$rDelOptions[2][] = $rStreamID;
							$rOptQuery .= '(' . intval($rStreamID) . ', 2, ' . self::$db->escape($rData['http_proxy']) . '),';
						}
					}

					if (!isset($rData['c_cookie'])) {
					} else {
						if (!(isset($rData['cookie']) && 0 < strlen($rData['cookie']))) {
						} else {
							$rDelOptions[17][] = $rStreamID;
							$rOptQuery .= '(' . intval($rStreamID) . ', 17, ' . self::$db->escape($rData['cookie']) . '),';
						}
					}

					if (!isset($rData['c_headers'])) {
					} else {
						if (!(isset($rData['headers']) && 0 < strlen($rData['headers']))) {
						} else {
							$rDelOptions[19][] = $rStreamID;
							$rOptQuery .= '(' . intval($rStreamID) . ', 19, ' . self::$db->escape($rData['headers']) . '),';
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
						self::$db->query('DELETE FROM `streams_options` WHERE `stream_id` IN (' . implode(',', $rDelIDs) . ') AND `argument_id` = ?;', intval($rOptionID));
					}
				}

				if (empty($rOptQuery)) {
				} else {
					$rOptQuery = rtrim($rOptQuery, ',');
					self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES ' . $rOptQuery . ';');
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
					self::$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES ' . $rAddQuery . ';');
				}

				CoreUtilities::updateStreams($rStreamIDs);

				if (!isset($rData['restart_on_edit'])) {
				} else {
					APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => array_values($rStreamIDs)));
				}
			}

			return array('status' => STATUS_SUCCESS);
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function massEditChannels($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (self::checkMinimumRequirements($rData)) {
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
					self::$db->query('SELECT `id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

					foreach (self::$db->get_rows() as $rRow) {
						$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: array());
					}
				}

				$rDeleteServers = $rProcessServers = $rStreamExists = array();
				self::$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

				foreach (self::$db->get_rows() as $rRow) {
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
						self::$db->query($rQuery, ...$rPrepare['data']);
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
										self::$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rServerID]);
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
					self::$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES ' . $rAddQuery . ';');
				}

				CoreUtilities::updateStreams($rStreamIDs);

				if (isset($rData['reencode_on_edit'])) {
					self::$db->query("UPDATE `streams_servers` SET `pids_create_channel` = '[]', `cchannel_rsources` = '[]' WHERE `stream_id` IN (" . implode(',', array_map('intval', $rStreamIDs)) . ');');

					if (empty($rEncQuery)) {
					} else {
						$rEncQuery = rtrim($rEncQuery, ',');
						self::$db->query('INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES ' . $rEncQuery . ';');
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
		} else {



			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function processStream($rData) {
		if (self::checkMinimumRequirements($rData)) {
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
								self::$db->query('SELECT `id`, `stream_display_name`, `stream_source`, `channel_id` FROM `streams` WHERE `type` = 1;');

								foreach (self::$db->get_rows() as $rRow) {
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
									self::$db->query('SELECT `id`, `data` FROM `epg`;');

									if (0 >= self::$db->num_rows()) {
									} else {
										foreach (self::$db->get_rows() as $rRow) {
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
											self::$db->query('SELECT `stream_source` FROM `streams` WHERE `id` = ?;', $rBackupID);

											if (0 >= self::$db->num_rows()) {
											} else {
												$rSources = (json_decode(self::$db->get_row()['stream_source'], true) ?: array());
												$rSources[] = $rURL;
												self::$db->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = ?;', json_encode($rSources), $rBackupID);
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

						if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rBouquetID = self::$db->last_insert_id();
							$rBouquetCreate[$rBouquet] = $rBouquetID;
						}
					}

					foreach (json_decode($rData['category_create_list'], true) as $rCategory) {
						$rPrepare = prepareArray(array('category_type' => 'live', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
						$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (!self::$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rCategoryID = self::$db->last_insert_id();
							$rCategoryCreate[$rCategory] = $rCategoryID;
						}
					}
				}

				foreach ($rImportStreams as $rImportStream) {
					if (!$rImportStream['update']) {
						$rImportArray = $rArray;

						if (!self::$rSettings['download_images']) {
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
							self::$db->query('SELECT `stream_display_name` FROM `providers_streams` WHERE `provider_id` = ? AND `stream_id` = ?;', $rSyncID, $rSyncStream);

							if (self::$db->num_rows() != 1) {
							} else {
								$rImportArray['stream_display_name'] = self::$db->get_row()['stream_display_name'];
							}
						}

						$rPrepare = prepareArray($rImportArray);
						$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (self::$db->query($rQuery, ...$rPrepare['data'])) {
							$rInsertID = self::$db->last_insert_id();
							$rStreamExists = array();

							if (!(isset($rData['edit']) || isset($rImportStream['id']))) {
							} else {
								self::$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);

								foreach (self::$db->get_rows() as $rRow) {
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
										self::$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rServerID]);
									} else {
										self::$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES(?, ?, ?, ?);', $rInsertID, $rServerID, $rParent, $rOD);
									}
								}
							}

							foreach ($rStreamExists as $rServerID => $rDBID) {
								if (in_array($rServerID, $rStreamsAdded)) {
								} else {
									deleteStream($rInsertID, $rServerID, false, false);
								}
							}
							self::$db->query('DELETE FROM `streams_options` WHERE `stream_id` = ?;', $rInsertID);

							if (!(isset($rData['user_agent']) && 0 < strlen($rData['user_agent']))) {
							} else {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 1, ?);', $rInsertID, $rData['user_agent']);
							}

							if (!(isset($rData['http_proxy']) && 0 < strlen($rData['http_proxy']))) {
							} else {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 2, ?);', $rInsertID, $rData['http_proxy']);
							}

							if (!(isset($rData['cookie']) && 0 < strlen($rData['cookie']))) {
							} else {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 17, ?);', $rInsertID, $rData['cookie']);
							}

							if (!(isset($rData['headers']) && 0 < strlen($rData['headers']))) {
							} else {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 19, ?);', $rInsertID, $rData['headers']);
							}

							if (isset($rData['skip_ffprobe']) && ($rData['skip_ffprobe'] == 'on' || $rData['skip_ffprobe'] == 1)) {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 21, ?);', $rInsertID, '1');
							}

							if (isset($rData['force_input_acodec']) && strlen(trim($rData['force_input_acodec'])) > 0) {
								self::$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 20, ?);', $rInsertID, trim($rData['force_input_acodec']));
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
								self::$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
							}

							foreach ($rCategoryCreate as $rCategory => $rID) {
								self::$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
							}

							return array('status' => STATUS_FAILURE, 'data' => $rData);
						}
					}
				}

				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			} else {
				return array('status' => STATUS_NO_SOURCES, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function orderCategories($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rPostCategories = json_decode($rData['categories'], true);

			if (0 >= count($rPostCategories)) {
			} else {
				foreach ($rPostCategories as $rOrder => $rPostCategory) {
					self::$db->query('UPDATE `streams_categories` SET `cat_order` = ?, `parent_id` = 0 WHERE `id` = ?;', intval($rOrder) + 1, $rPostCategory['id']);
				}
			}

			return array('status' => STATUS_SUCCESS);
		}
		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function orderServers($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rPostServers = json_decode($rData['server_order'], true);

			if (count($rPostServers) > 0) {
				foreach ($rPostServers as $rOrder => $rPostServer) {
					self::$db->query('UPDATE `servers` SET `order` = ? WHERE `id` = ?;', intval($rOrder) + 1, $rPostServer['id']);
				}
			}

			return array('status' => STATUS_SUCCESS);
		}
		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processCategory($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				$rArray = overwriteData(getCategory($rData['edit']), $rData);
			} else {
				$rArray = verifyPostTable('streams_categories', $rData);
				$rArray['cat_order'] = 99;
				unset($rArray['id']);
			}

			if (isset($rData['is_adult'])) {
				$rArray['is_adult'] = 1;
			} else {
				$rArray['is_adult'] = 0;
			}

			$rPrepare = prepareArray($rArray);
			$rQuery = 'REPLACE INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if (self::$db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = self::$db->last_insert_id();

				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			}

			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function moveStreams($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rType = intval($rData['content_type']);
			$rSource = intval($rData['source_server']);
			$rReplacement = intval($rData['replacement_server']);

			if (!(0 < $rSource && 0 < $rReplacement && $rSource != $rReplacement)) {
			} else {
				$rExisting = array();

				if ($rType == 0) {
					self::$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_id` = ?;', $rReplacement);

					foreach (self::$db->get_rows() as $rRow) {
						$rExisting[] = intval($rRow['stream_id']);
					}
				} else {
					self::$db->query('SELECT `streams_servers`.`stream_id` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams_servers`.`server_id` = ? AND `streams`.`type` = ?;', $rReplacement, $rType);

					foreach (self::$db->get_rows() as $rRow) {
						$rExisting[] = intval($rRow['stream_id']);
					}
				}

				self::$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_id` = ?;', $rSource);

				foreach (self::$db->get_rows() as $rRow) {
					if (!in_array(intval($rRow['stream_id']), $rExisting)) {
					} else {
						self::$db->query('DELETE FROM `streams_servers` WHERE `stream_id` = ? AND `server_id` = ?;', $rRow['stream_id'], $rSource);
					}
				}

				if ($rType == 0) {
					self::$db->query('UPDATE `streams_servers` SET `server_id` = ? WHERE `server_id` = ?;', $rReplacement, $rSource);
				} else {
					self::$db->query('UPDATE `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` SET `streams_servers`.`server_id` = ? WHERE `streams_servers`.`server_id` = ? AND `streams`.`type` = ?;', $rReplacement, $rSource, $rType);
				}
			}

			return array('status' => STATUS_SUCCESS);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function replaceDNS($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rOldDNS = str_replace('/', '\\/', $rData['old_dns']);
			$rNewDNS = str_replace('/', '\\/', $rData['new_dns']);
			self::$db->query('UPDATE `streams` SET `stream_source` = REPLACE(`stream_source`, ?, ?);', $rOldDNS, $rNewDNS);

			return array('status' => STATUS_SUCCESS);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function submitTicket($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				$rArray = overwriteData(getTicket($rData['edit']), $rData);
			} else {
				$rArray = verifyPostTable('tickets', $rData);
				unset($rArray['id']);
			}

			if (!(strlen($rData['title']) == 0 && !isset($rData['respond']) || strlen($rData['message']) == 0)) {



				if (!isset($rData['respond'])) {
					$rPrepare = prepareArray($rArray);
					$rQuery = 'REPLACE INTO `tickets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (self::$db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = self::$db->last_insert_id();
						self::$db->query('INSERT INTO `tickets_replies`(`ticket_id`, `admin_reply`, `message`, `date`) VALUES(?, 0, ?, ?);', $rInsertID, $rData['message'], time());

						return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
					}

					return array('status' => STATUS_FAILURE, 'data' => $rData);
				}

				$rTicket = getTicket($rData['respond']);

				if ($rTicket) {
					if (intval(self::$rUserInfo['id']) == intval($rTicket['member_id'])) {
						self::$db->query('UPDATE `tickets` SET `admin_read` = 0, `user_read` = 1 WHERE `id` = ?;', $rData['respond']);
						self::$db->query('INSERT INTO `tickets_replies`(`ticket_id`, `admin_reply`, `message`, `date`) VALUES(?, 0, ?, ?);', $rData['respond'], $rData['message'], time());
					} else {
						self::$db->query('UPDATE `tickets` SET `admin_read` = 0, `user_read` = 0 WHERE `id` = ?;', $rData['respond']);
						self::$db->query('INSERT INTO `tickets_replies`(`ticket_id`, `admin_reply`, `message`, `date`) VALUES(?, 1, ?, ?);', $rData['respond'], $rData['message'], time());
					}

					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rData['respond']));
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}

			return array('status' => STATUS_INVALID_DATA, 'data' => $rData);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processUA($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				$rArray = overwriteData(getUserAgent($rData['edit']), $rData);
			} else {
				$rArray = verifyPostTable('blocked_uas', $rData);
				unset($rArray['id']);
			}

			if (isset($rData['exact_match'])) {
				$rArray['exact_match'] = true;
			} else {
				$rArray['exact_match'] = false;
			}

			$rPrepare = prepareArray($rArray);
			$rQuery = 'REPLACE INTO `blocked_uas`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if (self::$db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = self::$db->last_insert_id();

				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			}

			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}



		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function processPlexSync($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				$rArray = overwriteData(getWatchFolder($rData['edit']), $rData);
			} else {
				$rArray = verifyPostTable('watch_folders', $rData);
				unset($rArray['id']);
			}

			if (is_array($rData['server_id'])) {
				$rServers = $rData['server_id'];
				$rArray['server_id'] = intval(array_shift($rServers));
				$rArray['server_add'] = '[' . implode(',', array_map('intval', $rServers)) . ']';
			} else {
				$rArray['server_id'] = intval($rData['server_id']);
				$rArray['server_add'] = null;
			}

			if (isset($rData['edit'])) {
				self::$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `plex_ip` = ? AND `id` <> ?;', $rData['library_id'], $rArray['server_id'], $rData['plex_ip'], $rArray['id']);
			} else {
				self::$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `plex_ip` = ?;', $rData['library_id'], $rArray['server_id'], $rData['plex_ip']);
			}

			if (0 >= self::$db->get_row()['count']) {
				$rArray['type'] = 'plex';
				$rArray['directory'] = $rData['library_id'];
				$rArray['plex_ip'] = $rData['plex_ip'];
				$rArray['plex_port'] = $rData['plex_port'];
				$rArray['plex_libraries'] = $rData['libraries'];
				$rArray['plex_username'] = $rData['username'];

				if (isset($rData['direct_proxy'])) {
					$rArray['direct_proxy'] = 1;
				} else {
					$rArray['direct_proxy'] = 0;
				}

				if (0 >= strlen($rData['password'])) {
				} else {
					$rArray['plex_password'] = $rData['password'];
				}

				foreach (array('remove_subtitles', 'check_tmdb', 'store_categories', 'scan_missing', 'auto_upgrade', 'read_native', 'movie_symlink', 'auto_encode', 'active') as $rKey) {
					if (isset($rData[$rKey])) {
						$rArray[$rKey] = 1;
					} else {
						$rArray[$rKey] = 0;
					}
				}
				$overrideBouquets = $rData['override_bouquets'] ?? [];
				$fallbackBouquets = $rData['fallback_bouquets'] ?? [];

				$rArray['category_id'] = intval($rData['override_category']);
				$rArray['fb_category_id'] = intval($rData['fallback_category']);
				$rArray['bouquets'] = '[' . implode(',', array_map('intval', $overrideBouquets)) . ']';
				$rArray['fb_bouquets'] = '[' . implode(',', array_map('intval', $fallbackBouquets)) . ']';
				$rArray['target_container'] = ($rData['target_container'] == 'auto' ? null : $rData['target_container']);
				$rPrepare = prepareArray($rArray);
				$rQuery = 'REPLACE INTO `watch_folders`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (self::$db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = self::$db->last_insert_id();

					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			} else {
				return array('status' => STATUS_EXISTS_DIR, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function processWatchFolder($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				$rArray = overwriteData(getWatchFolder($rData['edit']), $rData);
			} else {
				$rArray = verifyPostTable('watch_folders', $rData);
				unset($rArray['id']);
			}

			$rPath = $rData['selected_path'];

			if (0 < strlen($rPath) && $rPath != '/') {
				if (isset($rData['edit'])) {
					self::$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `type` = ? AND `id` <> ?;', $rPath, $rArray['server_id'], $rData['folder_type'], $rArray['id']);
				} else {
					self::$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `type` = ?;', $rPath, $rArray['server_id'], $rData['folder_type']);
				}

				if (0 >= self::$db->get_row()['count']) {
					$bouquets = is_array($rData['bouquets'] ?? null) ? $rData['bouquets'] : [];
					$fbBouquets = is_array($rData['fb_bouquets'] ?? null) ? $rData['fb_bouquets'] : [];

					$rArray['type'] = $rData['folder_type'];
					$rArray['directory'] = $rPath;
					$rArray['bouquets'] = '[' . implode(',', array_map('intval', $bouquets)) . ']';
					$rArray['fb_bouquets'] = '[' . implode(',', array_map('intval', $fbBouquets)) . ']';

					if (is_array($rData['allowed_extensions'] ?? null) && count($rData['allowed_extensions']) > 0) {
						$rArray['allowed_extensions'] = json_encode($rData['allowed_extensions']);
					} else {
						$rArray['allowed_extensions'] = '[]';
					}

					$rArray['target_container'] = ($rData['target_container'] == 'auto' ? null : $rData['target_container']);
					$rArray['category_id'] = intval($rData['category_id_' . $rData['folder_type']]);
					$rArray['fb_category_id'] = intval($rData['fb_category_id_' . $rData['folder_type']]);

					foreach (array('remove_subtitles', 'duplicate_tmdb', 'extract_metadata', 'fallback_title', 'disable_tmdb', 'ignore_no_match', 'auto_subtitles', 'auto_upgrade', 'read_native', 'movie_symlink', 'auto_encode', 'ffprobe_input', 'active') as $rKey) {
						if (isset($rData[$rKey])) {
							$rArray[$rKey] = 1;
						} else {
							$rArray[$rKey] = 0;
						}
					}
					$rPrepare = prepareArray($rArray);
					$rQuery = 'REPLACE INTO `watch_folders`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (self::$db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = self::$db->last_insert_id();

						return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
					}

					return array('status' => STATUS_FAILURE, 'data' => $rData);
				} else {
					return array('status' => STATUS_EXISTS_DIR, 'data' => $rData);
				}
			} else {
				return array('status' => STATUS_INVALID_DIR, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function massEditLines($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rArray = array();

			foreach (array('is_stalker', 'is_isplock', 'is_restreamer', 'is_trial') as $rItem) {
				if (!isset($rData['c_' . $rItem])) {
				} else {
					if (isset($rData[$rItem])) {
						$rArray[$rItem] = 1;
					} else {
						$rArray[$rItem] = 0;
					}
				}
			}

			if (!isset($rData['c_admin_notes'])) {
			} else {
				$rArray['admin_notes'] = $rData['admin_notes'];
			}

			if (!isset($rData['c_reseller_notes'])) {
			} else {
				$rArray['reseller_notes'] = $rData['reseller_notes'];
			}

			if (!isset($rData['c_forced_country'])) {
			} else {
				$rArray['forced_country'] = $rData['forced_country'];
			}

			if (!isset($rData['c_member_id'])) {
			} else {
				$rArray['member_id'] = intval($rData['member_id']);
			}

			if (!isset($rData['c_force_server_id'])) {
			} else {
				$rArray['force_server_id'] = intval($rData['force_server_id']);
			}

			if (!isset($rData['c_max_connections'])) {
			} else {
				$rArray['max_connections'] = intval($rData['max_connections']);
			}

			if (!isset($rData['c_exp_date'])) {
			} else {
				if (isset($rData['no_expire'])) {
					$rArray['exp_date'] = null;
				} else {
					try {
						$rDate = new DateTime($rData['exp_date']);
						$rArray['exp_date'] = $rDate->format('U');
					} catch (Exception $e) {
					}
				}
			}

			if (!isset($rData['c_access_output'])) {
			} else {
				$rOutputs = array();

				foreach ($rData['access_output'] as $rOutputID) {
					$rOutputs[] = $rOutputID;
				}
				$rArray['allowed_outputs'] = '[' . implode(',', array_map('intval', $rOutputs)) . ']';
			}

			if (!isset($rData['c_bouquets'])) {
			} else {
				$rArray['bouquet'] = array();

				foreach (json_decode($rData['bouquets_selected'], true) as $rBouquet) {
					if (!is_numeric($rBouquet)) {
					} else {
						$rArray['bouquet'][] = $rBouquet;
					}
				}
				$rArray['bouquet'] = sortArrayByArray($rArray['bouquet'], array_keys(getBouquetOrder()));
				$rArray['bouquet'] = '[' . implode(',', array_map('intval', $rArray['bouquet'])) . ']';
			}

			if (!isset($rData['reset_isp_lock'])) {
			} else {
				$rArray['isp_desc'] = '';
				$rArray['as_number'] = $rArray['isp_desc'];
			}

			$rUsers = confirmIDs(json_decode($rData['users_selected'], true));

			if (0 >= count($rUsers)) {
			} else {
				$rPrepare = prepareArray($rArray);

				if (0 >= count($rPrepare['data'])) {
				} else {
					$rQuery = 'UPDATE `lines` SET ' . $rPrepare['update'] . ' WHERE `id` IN (' . implode(',', $rUsers) . ');';
					self::$db->query($rQuery, ...$rPrepare['data']);
				}

				self::$db->query('SELECT `pair_id` FROM `lines` WHERE `pair_id` IN (' . implode(',', $rUsers) . ');');

				foreach (self::$db->get_rows() as $rRow) {
					syncDevices($rRow['pair_id']);
				}
				CoreUtilities::updateLines($rUsers);
			}

			return array('status' => STATUS_SUCCESS);
		} else {



			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function massEditMags($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rArray = array();
			$rUserArray = array();

			foreach (array('lock_device') as $rItem) {
				if (!isset($rData['c_' . $rItem])) {
				} else {
					if (isset($rData[$rItem])) {
						$rArray[$rItem] = 1;
					} else {
						$rArray[$rItem] = 0;
					}
				}
			}

			foreach (array('is_isplock', 'is_trial') as $rItem) {
				if (!isset($rData['c_' . $rItem])) {
				} else {
					if (isset($rData[$rItem])) {
						$rUserArray[$rItem] = 1;
					} else {
						$rUserArray[$rItem] = 0;
					}
				}
			}

			if (!isset($rData['c_modern_theme'])) {
			} else {
				if (isset($rData['modern_theme'])) {
					$rArray['theme_type'] = 0;
				} else {
					$rArray['theme_type'] = 1;
				}
			}

			if (!isset($rData['c_parent_password'])) {
			} else {
				$rArray['parent_password'] = $rData['parent_password'];
			}

			if (!isset($rData['c_admin_notes'])) {
			} else {
				$rUserArray['admin_notes'] = $rData['admin_notes'];
			}

			if (!isset($rData['c_reseller_notes'])) {
			} else {
				$rUserArray['reseller_notes'] = $rData['reseller_notes'];
			}

			if (!isset($rData['c_forced_country'])) {
			} else {
				$rUserArray['forced_country'] = $rData['forced_country'];
			}

			if (!isset($rData['c_member_id'])) {
			} else {
				$rUserArray['member_id'] = intval($rData['member_id']);
			}

			if (!isset($rData['c_force_server_id'])) {
			} else {
				$rUserArray['force_server_id'] = intval($rData['force_server_id']);
			}

			if (!isset($rData['c_exp_date'])) {
			} else {
				if (isset($rData['no_expire'])) {
					$rUserArray['exp_date'] = null;
				} else {
					try {
						$rDate = new DateTime($rData['exp_date']);
						$rUserArray['exp_date'] = $rDate->format('U');
					} catch (Exception $e) {
					}
				}
			}

			if (!isset($rData['c_bouquets'])) {
			} else {
				$rUserArray['bouquet'] = array();

				foreach (json_decode($rData['bouquets_selected'], true) as $rBouquet) {
					if (!is_numeric($rBouquet)) {
					} else {
						$rUserArray['bouquet'][] = $rBouquet;
					}
				}
				$rUserArray['bouquet'] = sortArrayByArray($rUserArray['bouquet'], array_keys(getBouquetOrder()));
				$rUserArray['bouquet'] = '[' . implode(',', array_map('intval', $rUserArray['bouquet'])) . ']';
			}

			if (!isset($rData['reset_isp_lock'])) {
			} else {
				$rUserArray['isp_desc'] = '';
				$rUserArray['as_number'] = $rUserArray['isp_desc'];
			}

			if (!isset($rData['reset_device_lock'])) {
			} else {
				$rArray['ver'] = '';
				$rArray['device_id2'] = $rArray['ver'];
				$rArray['device_id'] = $rArray['device_id2'];
				$rArray['hw_version'] = $rArray['device_id'];
				$rArray['image_version'] = $rArray['hw_version'];
				$rArray['stb_type'] = $rArray['image_version'];
				$rArray['sn'] = $rArray['stb_type'];
			}

			if (empty($rData['message_type'])) {
			} else {
				$rEvent = array('event' => $rData['message_type'], 'need_confirm' => 0, 'msg' => '', 'reboot_after_ok' => intval(isset($rData['reboot_portal'])));

				if ($rData['message_type'] == 'send_msg') {
					$rEvent['need_confirm'] = 1;
					$rEvent['msg'] = $rData['message'];
				} else {
					if ($rData['message_type'] == 'play_channel') {
						$rEvent['msg'] = intval($rData['selected_channel']);
						$rEvent['reboot_after_ok'] = 0;
					} else {
						$rEvent['need_confirm'] = 0;
						$rEvent['reboot_after_ok'] = 0;
					}
				}
			}

			$rDevices = json_decode($rData['devices_selected'], true);

			foreach ($rDevices as $rDevice) {
				$rDeviceInfo = getMag($rDevice);

				if (!$rDeviceInfo) {
				} else {
					if (empty($rData['message_type'])) {
					} else {
						self::$db->query('INSERT INTO `mag_events`(`status`, `mag_device_id`, `event`, `need_confirm`, `msg`, `reboot_after_ok`, `send_time`) VALUES (0, ?, ?, ?, ?, ?, ?);', $rDevice, $rEvent['event'], $rEvent['need_confirm'], $rEvent['msg'], $rEvent['reboot_after_ok'], time());
					}

					if (0 >= count($rArray)) {
					} else {
						$rPrepare = prepareArray($rArray);

						if (0 >= count($rPrepare['data'])) {
						} else {
							$rPrepare['data'][] = $rDevice;
							$rQuery = 'UPDATE `mag_devices` SET ' . $rPrepare['update'] . ' WHERE `mag_id` = ?;';
							self::$db->query($rQuery, ...$rPrepare['data']);
						}
					}

					if (0 >= count($rUserArray)) {
					} else {
						$rUserIDs = array();

						if (!isset($rDeviceInfo['user']['id'])) {
						} else {
							$rUserIDs[] = $rDeviceInfo['user']['id'];
						}

						if (!isset($rDeviceInfo['user']['paired'])) {
						} else {
							$rUserIDs[] = $rDeviceInfo['paired']['id'];
						}

						foreach ($rUserIDs as $rUserID) {
							$rPrepare = prepareArray($rUserArray);

							if (0 >= count($rPrepare['data'])) {
							} else {
								$rPrepare['data'][] = $rUserID;
								$rQuery = 'UPDATE `lines` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
								self::$db->query($rQuery, ...$rPrepare['data']);
								CoreUtilities::updateLine($rUserID);
							}
						}
					}
				}
			}

			return array('status' => STATUS_SUCCESS);
		} else {



			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function massEditEnigmas($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rArray = array();
			$rUserArray = array();

			foreach (array('is_isplock', 'is_trial') as $rItem) {
				if (!isset($rData['c_' . $rItem])) {
				} else {
					if (isset($rData[$rItem])) {
						$rUserArray[$rItem] = 1;
					} else {
						$rUserArray[$rItem] = 0;
					}
				}
			}

			if (!isset($rData['c_admin_notes'])) {
			} else {
				$rUserArray['admin_notes'] = $rData['admin_notes'];
			}

			if (!isset($rData['c_reseller_notes'])) {
			} else {
				$rUserArray['reseller_notes'] = $rData['reseller_notes'];
			}

			if (!isset($rData['c_forced_country'])) {
			} else {
				$rUserArray['forced_country'] = $rData['forced_country'];
			}

			if (!isset($rData['c_member_id'])) {
			} else {
				$rUserArray['member_id'] = intval($rData['member_id']);
			}

			if (!isset($rData['c_force_server_id'])) {
			} else {
				$rUserArray['force_server_id'] = intval($rData['force_server_id']);
			}

			if (!isset($rData['c_exp_date'])) {
			} else {
				if (isset($rData['no_expire'])) {
					$rUserArray['exp_date'] = null;
				} else {
					try {
						$rDate = new DateTime($rData['exp_date']);
						$rUserArray['exp_date'] = $rDate->format('U');
					} catch (Exception $e) {
					}
				}
			}

			if (!isset($rData['c_bouquets'])) {
			} else {
				$rUserArray['bouquet'] = array();

				foreach (json_decode($rData['bouquets_selected'], true) as $rBouquet) {
					if (!is_numeric($rBouquet)) {
					} else {
						$rUserArray['bouquet'][] = $rBouquet;
					}
				}
				$rUserArray['bouquet'] = sortArrayByArray($rUserArray['bouquet'], array_keys(getBouquetOrder()));
				$rUserArray['bouquet'] = '[' . implode(',', array_map('intval', $rUserArray['bouquet'])) . ']';
			}

			if (!isset($rData['reset_isp_lock'])) {
			} else {
				$rUserArray['isp_desc'] = '';
				$rUserArray['as_number'] = $rUserArray['isp_desc'];
			}

			if (!isset($rData['reset_device_lock'])) {
			} else {
				$rArray['token'] = '';
				$rArray['lversion'] = $rArray['token'];
				$rArray['cpu'] = $rArray['lversion'];
				$rArray['enigma_version'] = $rArray['cpu'];
				$rArray['modem_mac'] = $rArray['enigma_version'];
				$rArray['local_ip'] = $rArray['modem_mac'];
			}

			$rDevices = json_decode($rData['devices_selected'], true);

			foreach ($rDevices as $rDevice) {
				$rDeviceInfo = getEnigma($rDevice);

				if (!$rDeviceInfo) {
				} else {
					if (0 >= count($rArray)) {
					} else {
						$rPrepare = prepareArray($rArray);

						if (0 >= count($rPrepare['data'])) {
						} else {
							$rPrepare['data'][] = $rDevice;
							$rQuery = 'UPDATE `enigma2_devices` SET ' . $rPrepare['update'] . ' WHERE `device_id` = ?;';
							self::$db->query($rQuery, ...$rPrepare['data']);
						}
					}

					if (0 >= count($rUserArray)) {
					} else {
						$rUserIDs = array();

						if (!isset($rDeviceInfo['user']['id'])) {
						} else {
							$rUserIDs[] = $rDeviceInfo['user']['id'];
						}

						if (!isset($rDeviceInfo['user']['paired'])) {
						} else {
							$rUserIDs[] = $rDeviceInfo['paired']['id'];
						}

						foreach ($rUserIDs as $rUserID) {
							$rPrepare = prepareArray($rUserArray);

							if (0 >= count($rPrepare['data'])) {
							} else {
								$rPrepare['data'][] = $rUserID;
								$rQuery = 'UPDATE `lines` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
								self::$db->query($rQuery, ...$rPrepare['data']);
								CoreUtilities::updateLine($rUserID);
							}
						}
					}
				}
			}

			return array('status' => STATUS_SUCCESS);
		} else {



			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function massEditUsers($rData) {
		if (self::checkMinimumRequirements($rData)) {
			$rArray = array();

			foreach (array('status') as $rItem) {
				if (!isset($rData['c_' . $rItem])) {
				} else {
					if (isset($rData[$rItem])) {
						$rArray[$rItem] = 1;
					} else {
						$rArray[$rItem] = 0;
					}
				}
			}

			if (!isset($rData['c_owner_id'])) {
			} else {
				$rArray['owner_id'] = intval($rData['owner_id']);
			}

			if (!isset($rData['c_member_group_id'])) {
			} else {
				$rArray['member_group_id'] = intval($rData['member_group_id']);
			}

			if (!isset($rData['c_reseller_dns'])) {
			} else {
				$rArray['reseller_dns'] = $rData['reseller_dns'];
			}

			if (!isset($rData['c_override'])) {
			} else {
				$rOverride = array();

				foreach ($rData as $rKey => $rCredits) {
					if (substr($rKey, 0, 9) != 'override_') {
					} else {
						$rID = intval(explode('override_', $rKey)[1]);

						if (0 < strlen($rCredits)) {
							$rCredits = intval($rCredits);
						} else {
							$rCredits = null;
						}

						if (!$rCredits) {
						} else {
							$rOverride[$rID] = array('assign' => 1, 'official_credits' => $rCredits);
						}
					}
				}
				$rArray['override_packages'] = json_encode($rOverride);
			}

			$rUsers = confirmIDs(json_decode($rData['users_selected'], true));

			if (0 >= count($rUsers)) {
			} else {
				if (!(isset($rData['c_owner_id']) && $rUser == $rArray['owner_id'])) {
				} else {
					unset($rArray['owner_id']);
				}

				$rPrepare = prepareArray($rArray);

				if (0 >= count($rPrepare['data'])) {
				} else {
					$rQuery = 'UPDATE `users` SET ' . $rPrepare['update'] . ' WHERE `id` IN (' . implode(',', $rUsers) . ');';
					self::$db->query($rQuery, ...$rPrepare['data']);
				}
			}

			return array('status' => STATUS_SUCCESS);
		} else {



			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function processLine($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (isset($rData['edit'])) {
				if (hasPermissions('adv', 'edit_user')) {


					$rArray = overwriteData(getUser($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (hasPermissions('adv', 'add_user')) {


					$rArray = verifyPostTable('lines', $rData);
					$rArray['created_at'] = time();
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (strlen($rData['username']) != 0) {
			} else {
				$rArray['username'] = generateString(10);
			}

			if (strlen($rData['password']) != 0) {
			} else {
				$rArray['password'] = generateString(10);
			}

			foreach (array('max_connections', 'enabled', 'admin_enabled') as $rSelection) {
				if (isset($rData[$rSelection])) {
					$rArray[$rSelection] = intval($rData[$rSelection]);
				} else {
					$rArray[$rSelection] = 1;
				}
			}

			foreach (array('is_stalker', 'is_restreamer', 'is_trial', 'is_isplock', 'bypass_ua') as $rSelection) {
				if (isset($rData[$rSelection])) {
					$rArray[$rSelection] = 1;
				} else {
					$rArray[$rSelection] = 0;
				}
			}

			if (strlen($rData['isp_clear']) != 0) {
			} else {
				$rArray['isp_desc'] = '';
				$rArray['as_number'] = null;
			}

			$rArray['bouquet'] = sortArrayByArray(array_values(json_decode($rData['bouquets_selected'], true)), array_keys(getBouquetOrder()));
			$rArray['bouquet'] = '[' . implode(',', array_map('intval', $rArray['bouquet'])) . ']';

			if (isset($rData['exp_date']) && !isset($rData['no_expire'])) {
				if (!(0 < strlen($rData['exp_date']) && $rData['exp_date'] != '1970-01-01')) {
				} else {
					try {
						$rDate = new DateTime($rData['exp_date']);
						$rArray['exp_date'] = $rDate->format('U');
					} catch (Exception $e) {
						return array('status' => STATUS_INVALID_DATE, 'data' => $rData);
					}
				}
			} else {
				$rArray['exp_date'] = null;
			}

			if ($rArray['member_id']) {
			} else {
				$rArray['member_id'] = self::$rUserInfo['id'];
			}

			if (isset($rData['allowed_ips'])) {
				if (is_array($rData['allowed_ips'])) {
				} else {
					$rData['allowed_ips'] = array($rData['allowed_ips']);
				}

				$rArray['allowed_ips'] = json_encode($rData['allowed_ips']);
			} else {
				$rArray['allowed_ips'] = '[]';
			}

			if (isset($rData['allowed_ua'])) {
				if (is_array($rData['allowed_ua'])) {
				} else {
					$rData['allowed_ua'] = array($rData['allowed_ua']);
				}

				$rArray['allowed_ua'] = json_encode($rData['allowed_ua']);
			} else {
				$rArray['allowed_ua'] = '[]';
			}

			$rOutputs = array();

			if (!isset($rData['access_output'])) {
			} else {
				foreach ($rData['access_output'] as $rOutputID) {
					$rOutputs[] = $rOutputID;
				}
			}

			$rArray['allowed_outputs'] = '[' . implode(',', array_map('intval', $rOutputs)) . ']';

			if (!checkExists('lines', 'username', $rArray['username'], 'id', $rData['edit'])) {
				$rPrepare = prepareArray($rArray);


				$rQuery = 'REPLACE INTO `lines`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (self::$db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = self::$db->last_insert_id();
					syncDevices($rInsertID);
					CoreUtilities::updateLine($rInsertID);

					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}

			return array('status' => STATUS_EXISTS_USERNAME, 'data' => $rData);
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function scheduleRecording($rData) {
		if (self::checkMinimumRequirements($rData)) {
			if (hasPermissions('adv', 'add_stream')) {


				if (!empty($rData['title'])) {


					if (!empty($rData['source_id'])) {


						$rArray = verifyPostTable('recordings', $rData);
						$rArray['bouquets'] = '[' . implode(',', array_map('intval', $rData['bouquets'])) . ']';
						$rArray['category_id'] = '[' . implode(',', array_map('intval', $rData['category_id'])) . ']';
						$rPrepare = prepareArray($rArray);
						$rQuery = 'REPLACE INTO `recordings`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (self::$db->query($rQuery, ...$rPrepare['data'])) {
							$rInsertID = self::$db->last_insert_id();

							return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
						}

						return array('status' => STATUS_FAILURE, 'data' => $rData);
					}

					return array('status' => STATUS_NO_SOURCE);
				}

				return array('status' => STATUS_NO_TITLE);
			}

			exit();
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}
}
