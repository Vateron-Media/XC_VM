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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return BouquetService::process($rData, self::$db, 'getBouquet', 'scanBouquet');
	}

	public static function processCode($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return CodeService::process(self::$db, $rData, 'getCode', 'updateCodes');
	}

	public static function processHMAC($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return HMACService::process(self::$db, $rData, self::$rSettings, 'getHMACToken');
	}

	public static function reorderBouquet($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return BouquetService::reorder($rData, self::$db);
	}

	public static function editAdminProfile($rData) {
		global $allowedLangs;
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return ProfileService::editAdminProfile(self::$db, $rData, self::$rUserInfo, $allowedLangs);
	}

	public static function blockIP($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return BlocklistService::blockIP(self::$db, $rData);
	}

	public static function sortBouquets($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return BouquetService::sort($rData, self::$db, 'getUserBouquets', 'getPackages', 'sortArrayByArray', array('CoreUtilities', 'updateLine'));
	}

	public static function setChannelOrder($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return ChannelService::setOrder($rData, self::$db);
	}

	public static function processChannel($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return ChannelService::process($rData, self::$db, self::$rSettings);
	}

	public static function processEPG($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return EpgService::process($rData, self::$db, 'getEPG');
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return EpisodeService::process(self::$db, self::$rSettings, $rData);
	}

	public static function massEditEpisodes($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return EpisodeService::massEdit(self::$db, $rData);
	}

	public static function processGroup($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return GroupService::process($rData);
	}

	public static function processGroupLegacy($rData) {
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return BlocklistService::processISP(self::$db, $rData, 'getISP');
	}

	public static function processLogin($rData, $rBypassRecaptcha = false) {
		return Authenticator::login(self::$db, self::$rSettings, $rData, $rBypassRecaptcha);
	}

	public static function massDeleteStreams($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return StreamService::massDelete($rData);
	}

	public static function massDeleteMovies($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return MovieService::massDelete($rData);
	}

	public static function massDeleteLines($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return LineService::massDelete($rData);
	}

	public static function massDeleteUsers($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return UserService::massDelete($rData);
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return MagService::massDelete($rData);
	}

	public static function massDeleteEnigmas($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return EnigmaService::massDelete($rData);
	}

	public static function massDeleteSeries($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return SeriesService::massDelete($rData);
	}

	public static function massDeleteEpisodes($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return EpisodeService::massDelete($rData);
	}

	public static function processMovie($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return MovieService::process(self::$db, self::$rSettings, $rData);
	}

	public static function processMovieLegacy($rData) {
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return MovieService::massEdit(self::$db, $rData);
	}

	public static function processPackage($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return PackageService::process(self::$db, $rData, 'getPackage', 'getBouquetOrder', 'sortArrayByArray');
	}

	public static function processMAG($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return MagService::process($rData);
	}

	public static function processMAGLegacy($rData) {
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return EnigmaService::process($rData);
	}

	public static function processEnigmaLegacy($rData) {
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return UserService::process($rData, $rBypassAuth);
	}

	public static function processUserLegacy($rData, $rBypassAuth = false) {
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return BlocklistService::processRTMPIP(self::$db, $rData, 'getRTMPIP');
	}

	public static function importSeries($rData) {
		if (!hasPermissions('adv', 'import_movies')) {
			exit();
		}
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return SeriesService::import($rData);
	}

	public static function importSeriesLegacy($rData) {
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
		if (!hasPermissions('adv', 'import_movies')) {
			exit();
		}
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return MovieService::import($rData);
	}

	public static function importMoviesLegacy($rData) {
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return SeriesService::process(self::$db, self::$rSettings, $rData);
	}

	public static function processSeriesLegacy($rData) {
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return SeriesService::massEdit(self::$db, $rData);
	}

	public static function processServer($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return ServerService::process($rData, self::$db);
	}

	public static function processProxy($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return ServerService::processProxy($rData, self::$db);
	}

	public static function installServer($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return ServerService::install($rData, self::$db, self::$rServers, self::$rProxyServers);
	}

	public static function editSettings($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return SettingsService::edit(self::$db, $rData, 'clearSettingsCache');
	}

	public static function editBackupSettings($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return SettingsService::editBackup(self::$db, $rData, 'clearSettingsCache');
	}

	public static function editCacheCron($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return SettingsService::editCacheCron(self::$db, $rData, 'clearSettingsCache');
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return StreamService::massEdit($rData, self::$db);
	}

	public static function massEditChannels($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return ChannelService::massEdit($rData, self::$db);
	}

	public static function processStream($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return StreamService::process($rData, self::$db, self::$rSettings);
	}

	public static function orderCategories($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return CategoryService::reorder($rData, self::$db);
	}

	public static function orderServers($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return ServerService::reorder($rData, self::$db);
	}

	public static function processCategory($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return CategoryService::process($rData, self::$db);
	}

	public static function moveStreams($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return StreamService::move($rData, self::$db);
	}

	public static function replaceDNS($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return StreamService::replaceDNS($rData, self::$db);
	}

	public static function submitTicket($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return TicketService::submit(self::$db, $rData, self::$rUserInfo, 'getTicket');
	}

	public static function processUA($rData) {
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return BlocklistService::processUA(self::$db, $rData, 'getUserAgent');
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return LineService::massEdit($rData);
	}

	public static function massEditLinesLegacy($rData) {
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return MagService::massEdit($rData);
	}

	public static function massEditMagsLegacy($rData) {
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return EnigmaService::massEdit($rData);
	}

	public static function massEditEnigmasLegacy($rData) {
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return UserService::massEdit($rData);
	}

	public static function massEditUsersLegacy($rData) {
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
		if (!self::checkMinimumRequirements($rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		return LineService::process($rData);
	}

	public static function processLineLegacy($rData) {
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
