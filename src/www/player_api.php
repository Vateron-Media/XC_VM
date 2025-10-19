<?php

register_shutdown_function('shutdown');
require './stream/init.php';
set_time_limit(0);

if (StreamingUtilities::$rSettings['force_epg_timezone']) {
	date_default_timezone_set('UTC');
}

$rDeny = true;

if (StreamingUtilities::$rSettings['disable_player_api']) {
	$rDeny = false;
	generateError('PLAYER_API_DISABLED');
}

$rPanelAPI = false;

if (strtolower(explode('.', ltrim(parse_url($_SERVER['REQUEST_URI'])['path'], '/'))[0]) == 'panel_api') {
	if (!StreamingUtilities::$rSettings['legacy_panel_api']) {
		$rDeny = false;
		generateError('LEGACY_PANEL_API_DISABLED');
	} else {
		$rPanelAPI = true;
	}
}

$rIP = $_SERVER['REMOTE_ADDR'];
$rUserAgent = trim($_SERVER['HTTP_USER_AGENT']);
$rOffset = (empty(StreamingUtilities::$rRequest['params']['offset']) ? 0 : abs(intval(StreamingUtilities::$rRequest['params']['offset'])));
$rLimit = (empty(StreamingUtilities::$rRequest['params']['items_per_page']) ? 0 : abs(intval(StreamingUtilities::$rRequest['params']['items_per_page'])));
$rNameTypes = array('live' => 'Live Streams', 'movie' => 'Movies', 'created_live' => 'Created Channels', 'radio_streams' => 'Radio Stations', 'series' => 'TV Series');
$rDomainName = StreamingUtilities::getDomainName();
$rDomain = parse_url($rDomainName)['host'];
$rValidActions = array('get_epg', 200 => 'get_vod_categories', 201 => 'get_live_categories', 202 => 'get_live_streams', 203 => 'get_vod_streams', 204 => 'get_series_info', 205 => 'get_short_epg', 206 => 'get_series_categories', 207 => 'get_simple_data_table', 208 => 'get_series', 209 => 'get_vod_info');
$output = array();
$rAction = (!empty(StreamingUtilities::$rRequest['action']) && (in_array(StreamingUtilities::$rRequest['action'], $rValidActions) || array_key_exists(StreamingUtilities::$rRequest['action'], $rValidActions)) ? StreamingUtilities::$rRequest['action'] : '');

if (isset($rValidActions[$rAction])) {
	$rAction = $rValidActions[$rAction];
}

if ($rPanelAPI && empty($rAction)) {
	$rGetChannels = true;
} else {
	$rGetChannels = in_array($rAction, array('get_series', 'get_vod_streams', 'get_live_streams'));
}

if ($rGetChannels) {
	StreamingUtilities::$rBouquets = StreamingUtilities::getCache('bouquets');
}

if ($rPanelAPI && empty($rAction) || in_array($rAction, array('get_vod_categories', 'get_series_categories', 'get_live_categories'))) {
	StreamingUtilities::$rCategories = StreamingUtilities::getCache('categories');
}

$rExtract = array('offset' => $rOffset, 'items_per_page' => $rLimit);

if (isset(StreamingUtilities::$rRequest['username']) && isset(StreamingUtilities::$rRequest['password'])) {
	$rUsername = StreamingUtilities::$rRequest['username'];
	$rPassword = StreamingUtilities::$rRequest['password'];

	if (empty($rUsername) || empty($rPassword)) {
		generateError('NO_CREDENTIALS');
	}

	$rUserInfo = StreamingUtilities::getUserInfo(null, $rUsername, $rPassword, $rGetChannels);
} else {
	if (isset(StreamingUtilities::$rRequest['token'])) {
		$rToken = StreamingUtilities::$rRequest['token'];

		if (empty($rToken)) {
			generateError('NO_CREDENTIALS');
		}

		$rUserInfo = StreamingUtilities::getUserInfo(null, $rToken, null, $rGetChannels);
	}
}

ini_set('memory_limit', -1);

if ($rUserInfo) {
	$rDeny = false;
	$rValidUser = false;

	if ($rUserInfo['admin_enabled'] == 1 && $rUserInfo['enabled'] == 1 && (is_null($rUserInfo['exp_date']) || time() < $rUserInfo['exp_date'])) {
		$rValidUser = true;
	} elseif (!$rUserInfo['admin_enabled']) {
		generateError('BANNED');
	} elseif (!$rUserInfo['enabled']) {
		generateError('DISABLED');
	} else {
		generateError('EXPIRED');
	}

	StreamingUtilities::checkAuthFlood($rUserInfo);
	header('Content-Type: application/json');

	if (isset($_SERVER['HTTP_ORIGIN'])) {
		header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
	}

	header('Access-Control-Allow-Credentials: true');

	switch ($rAction) {
		case 'get_epg':
			if (!empty(StreamingUtilities::$rRequest['stream_id']) && (is_null($rUserInfo['exp_date']) || time() < $rUserInfo['exp_date'])) {
				$rFromNow = !empty(StreamingUtilities::$rRequest['from_now']) && 0 < StreamingUtilities::$rRequest['from_now'];

				if (is_numeric(StreamingUtilities::$rRequest['stream_id']) && !isset(StreamingUtilities::$rRequest['multi'])) {
					$rMulti = false;
					$rStreamIDs = array(intval(StreamingUtilities::$rRequest['stream_id']));
				} else {
					$rMulti = true;
					$rStreamIDs = array_map('intval', explode(',', StreamingUtilities::$rRequest['stream_id']));
				}

				$rEPGs = array();

				if (0 >= count($rStreamIDs)) {
				} else {
					foreach ($rStreamIDs as $rStreamID) {
						if (!file_exists(EPG_PATH . 'stream_' . intval($rStreamID))) {
						} else {
							$rRows = igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStreamID));

							foreach ($rRows as $rRow) {
								if (!($rFromNow && $rRow['end'] < time())) {
									$rRow['title'] = base64_encode($rRow['title']);
									$rRow['description'] = base64_encode($rRow['description']);
									$rRow['start'] = intval($rRow['start']);
									$rRow['end'] = intval($rRow['end']);

									if ($rMulti) {
										$rEPGs[$rStreamID][] = $rRow;
									} else {
										$rEPGs[] = $rRow;
									}
								}
							}
						}
					}
				}

				echo json_encode($rEPGs);

				exit();
			}

			echo json_encode(array());

			exit();


		case 'get_series_info':
			$rSeriesID = (empty(StreamingUtilities::$rRequest['series_id']) ? 0 : intval(StreamingUtilities::$rRequest['series_id']));

			if (StreamingUtilities::$rCached) {
				$rSeriesInfo = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_' . $rSeriesID));
				$rRows = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'episodes_' . $rSeriesID));
			} else {
				StreamingUtilities::$db->query('SELECT * FROM `streams_episodes` t1 INNER JOIN `streams` t2 ON t2.id=t1.stream_id WHERE t1.series_id = ? ORDER BY t1.season_num ASC, t1.episode_num ASC', $rSeriesID);
				$rRows = StreamingUtilities::$db->get_rows(true, 'season_num', false);
				StreamingUtilities::$db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $rSeriesID);
				$rSeriesInfo = StreamingUtilities::$db->get_row();
			}

			$output['seasons'] = array();

			foreach ((!empty($rSeriesInfo['seasons']) ? array_values(json_decode($rSeriesInfo['seasons'], true)) : array()) as $rSeason) {
				$rSeason['cover'] = StreamingUtilities::validateImage($rSeason['cover']);
				$rSeason['cover_big'] = StreamingUtilities::validateImage($rSeason['cover_big']);
				$output['seasons'][] = $rSeason;
			}
			$rBackdrops = json_decode($rSeriesInfo['backdrop_path'], true);

			if (count($rBackdrops) > 0) {
				foreach (range(0, count($rBackdrops) - 1) as $i) {
					$rBackdrops[$i] = StreamingUtilities::validateImage($rBackdrops[$i]);
				}
			}

			$output['info'] = array('name' => StreamingUtilities::formatTitle($rSeriesInfo['title'], $rSeriesInfo['year']), 'title' => $rSeriesInfo['title'], 'year' => $rSeriesInfo['year'], 'cover' => StreamingUtilities::validateImage($rSeriesInfo['cover']), 'plot' => $rSeriesInfo['plot'], 'cast' => $rSeriesInfo['cast'], 'director' => $rSeriesInfo['director'], 'genre' => $rSeriesInfo['genre'], 'release_date' => $rSeriesInfo['release_date'], 'releaseDate' => $rSeriesInfo['release_date'], 'last_modified' => $rSeriesInfo['last_modified'], 'rating' => number_format($rSeriesInfo['rating'], 0), 'rating_5based' => number_format($rSeriesInfo['rating'] * 0.5, 1) + 0, 'backdrop_path' => $rBackdrops, 'youtube_trailer' => $rSeriesInfo['youtube_trailer'], 'episode_run_time' => $rSeriesInfo['episode_run_time'], 'category_id' => strval(json_decode($rSeriesInfo['category_id'], true)[0]), 'category_ids' => json_decode($rSeriesInfo['category_id'], true));

			foreach ($rRows as $rSeason => $rEpisodes) {
				$rNum = 1;

				foreach ($rEpisodes as $rEpisode) {
					if (StreamingUtilities::$rCached) {
						$rEpisodeData = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rEpisode['stream_id'])))['info'];
					} else {
						$rEpisodeData = $rEpisode;
					}

					if (StreamingUtilities::$rSettings['api_redirect']) {
						$rEncData = 'series/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rEpisodeData['id'] . '/' . $rEpisodeData['target_container'];
						$rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
						$rURL = $rDomainName . 'play/' . $rToken;
					} else {
						$rURL = '';
					}

					$rProperties = (!empty($rEpisodeData['movie_properties']) ? json_decode($rEpisodeData['movie_properties'], true) : '');
					$rProperties['cover_big'] = StreamingUtilities::validateImage($rProperties['cover_big']);
					$rProperties['movie_image'] = StreamingUtilities::validateImage($rProperties['movie_image']);

					if (!$rProperties['cover_big']) {
						$rProperties['cover_big'] = $rProperties['movie_image'];
					}

					if (is_array($rProperties['backdrop_path']) && count($rProperties['backdrop_path']) > 0) {
						foreach ($rProperties['backdrop_path'] as $key => $backdrop) {
							if (!empty($backdrop)) {
								$rProperties['backdrop_path'][$key] = StreamingUtilities::validateImage($backdrop);
							}
						}
					}

					$rSubtitles = array();

					if (is_array($rProperties['subtitle'])) {
						$i = 0;

						foreach ($rProperties['subtitle'] as $rSubtitle) {
							$rSubtitles[] = array('index' => $i, 'language' => ($rSubtitle['tags']['language'] ?: null), 'title' => ($rSubtitle['tags']['title'] ?: null));
							$i++;
						}
					}

					foreach (array('audio', 'video', 'subtitle') as $rKey) {
						if (isset($rProperties[$rKey])) {
							unset($rProperties[$rKey]);
						}
					}
					$output['episodes'][$rSeason][] = array('id' => $rEpisode['stream_id'], 'episode_num' => $rEpisode['episode_num'], 'title' => $rEpisodeData['stream_display_name'], 'container_extension' => $rEpisodeData['target_container'], 'info' => $rProperties, 'subtitles' => $rSubtitles, 'custom_sid' => strval($rEpisodeData['custom_sid']), 'added' => ($rEpisodeData['added'] ?: ''), 'season' => $rSeason, 'direct_source' => $rURL);
				}
			}

			break;

		case 'get_series':
			$rCategoryIDSearch = (empty(StreamingUtilities::$rRequest['category_id']) ? null : intval(StreamingUtilities::$rRequest['category_id']));
			$rMovieNum = 0;

			if (count($rUserInfo['series_ids']) > 0) {
				if (StreamingUtilities::$rCached) {
					if (StreamingUtilities::$rSettings['vod_sort_newest']) {
						$rUserInfo['series_ids'] = StreamingUtilities::sortSeries($rUserInfo['series_ids']);
					}

					foreach ($rUserInfo['series_ids'] as $rSeriesID) {
						$rSeriesItem = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_' . $rSeriesID));
						$rBackdrops = json_decode($rSeriesItem['backdrop_path'], true);

						if (count($rBackdrops) > 0) {
							foreach (range(0, count($rBackdrops) - 1) as $i) {
								$rBackdrops[$i] = StreamingUtilities::validateImage($rBackdrops[$i]);
							}
						}

						$rCategoryIDs = json_decode($rSeriesItem['category_id'], true);

						foreach ($rCategoryIDs as $rCategoryID) {
							if (!$rCategoryIDSearch || $rCategoryIDSearch == $rCategoryID) {
								$output[] = array('num' => ++$rMovieNum, 'name' => StreamingUtilities::formatTitle($rSeriesItem['title'], $rSeriesItem['year']), 'title' => $rSeriesItem['title'], 'year' => $rSeriesItem['year'], 'stream_type' => 'series', 'series_id' => (int) $rSeriesItem['id'], 'cover' => StreamingUtilities::validateImage($rSeriesItem['cover']), 'plot' => $rSeriesItem['plot'], 'cast' => $rSeriesItem['cast'], 'director' => $rSeriesItem['director'], 'genre' => $rSeriesItem['genre'], 'release_date' => $rSeriesItem['release_date'], 'releaseDate' => $rSeriesItem['release_date'], 'last_modified' => $rSeriesItem['last_modified'], 'rating' => number_format($rSeriesItem['rating'], 0), 'rating_5based' => number_format($rSeriesItem['rating'] * 0.5, 1) + 0, 'backdrop_path' => $rBackdrops, 'youtube_trailer' => $rSeriesItem['youtube_trailer'], 'episode_run_time' => $rSeriesItem['episode_run_time'], 'category_id' => strval($rCategoryID), 'category_ids' => $rCategoryIDs);
							}

							if (!($rCategoryIDSearch || StreamingUtilities::$rSettings['show_category_duplicates'])) {
								break;
							}
						}
					}
				} else {
					if (!empty($rUserInfo['series_ids'])) {
						if (StreamingUtilities::$rSettings['vod_sort_newest']) {
							StreamingUtilities::$db->query('SELECT *, (SELECT MAX(`streams`.`added`) FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) AS `last_modified_stream` FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rUserInfo['series_ids'])) . ') ORDER BY `last_modified_stream` DESC, `last_modified` DESC;');
						} else {
							StreamingUtilities::$db->query('SELECT * FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rUserInfo['series_ids'])) . ') ORDER BY FIELD(`id`,' . implode(',', $rUserInfo['series_ids']) . ') ASC;');
						}

						$rSeries = StreamingUtilities::$db->get_rows(true, 'id');

						foreach ($rSeries as $rSeriesItem) {
							if (isset($rSeriesItem['last_modified_stream']) && !empty($rSeriesItem['last_modified_stream'])) {
								$rSeriesItem['last_modified'] = $rSeriesItem['last_modified_stream'];
							}
							$rBackdrops = json_decode($rSeriesItem['backdrop_path'], true);

							if (!empty($rBackdrops)) {
								foreach (range(0, count($rBackdrops) - 1) as $i) {
									$rBackdrops[$i] = StreamingUtilities::validateImage($rBackdrops[$i]);
								}
							}

							$rCategoryIDs = json_decode($rSeriesItem['category_id'], true);

							foreach ($rCategoryIDs as $rCategoryID) {
								if (!$rCategoryIDSearch || $rCategoryIDSearch == $rCategoryID) {
									$output[] = array('num' => ++$rMovieNum, 'name' => StreamingUtilities::formatTitle($rSeriesItem['title'], $rSeriesItem['year']), 'title' => $rSeriesItem['title'], 'year' => $rSeriesItem['year'], 'stream_type' => 'series', 'series_id' => (int) $rSeriesItem['id'], 'cover' => StreamingUtilities::validateImage($rSeriesItem['cover']), 'plot' => $rSeriesItem['plot'], 'cast' => $rSeriesItem['cast'], 'director' => $rSeriesItem['director'], 'genre' => $rSeriesItem['genre'], 'release_date' => $rSeriesItem['release_date'], 'releaseDate' => $rSeriesItem['release_date'], 'last_modified' => $rSeriesItem['last_modified'], 'rating' => number_format($rSeriesItem['rating'], 0), 'rating_5based' => number_format($rSeriesItem['rating'] * 0.5, 1) + 0, 'backdrop_path' => $rBackdrops, 'youtube_trailer' => $rSeriesItem['youtube_trailer'], 'episode_run_time' => $rSeriesItem['episode_run_time'], 'category_id' => strval($rCategoryID), 'category_ids' => $rCategoryIDs);
								}

								if (!$rCategoryIDSearch && !StreamingUtilities::$rSettings['show_category_duplicates']) {
									break;
								}
							}
						}
					}
				}
			}

			break;

		case 'get_vod_categories':
			$rCategories = StreamingUtilities::getCategories('movie');

			foreach ($rCategories as $rCategory) {
				if (in_array($rCategory['id'], $rUserInfo['category_ids'])) {
					$output[] = array('category_id' => strval($rCategory['id']), 'category_name' => $rCategory['category_name'], 'parent_id' => 0);
				}
			}

			break;

		case 'get_series_categories':
			$rCategories = StreamingUtilities::getCategories('series');

			foreach ($rCategories as $rCategory) {
				if (in_array($rCategory['id'], $rUserInfo['category_ids'])) {
					$output[] = array('category_id' => strval($rCategory['id']), 'category_name' => $rCategory['category_name'], 'parent_id' => 0);
				}
			}

			break;

		case 'get_live_categories':
			$rCategories = array_merge(StreamingUtilities::getCategories('live'), StreamingUtilities::getCategories('radio'));

			foreach ($rCategories as $rCategory) {
				if (in_array($rCategory['id'], $rUserInfo['category_ids'])) {
					$output[] = array('category_id' => strval($rCategory['id']), 'category_name' => $rCategory['category_name'], 'parent_id' => 0);
				}
			}

			break;

		case 'get_simple_data_table':
			$output['epg_listings'] = array();

			if (empty(StreamingUtilities::$rRequest['stream_id'])) {
			} else {
				if (is_numeric(StreamingUtilities::$rRequest['stream_id']) && !isset(StreamingUtilities::$rRequest['multi'])) {
					$rMulti = false;
					$rStreamIDs = array(intval(StreamingUtilities::$rRequest['stream_id']));
				} else {
					$rMulti = true;
					$rStreamIDs = array_map('intval', explode(',', StreamingUtilities::$rRequest['stream_id']));
				}

				if (0 >= count($rStreamIDs)) {
				} else {
					$rArchiveInfo = array();

					if (StreamingUtilities::$rCached) {
						foreach ($rStreamIDs as $rStreamID) {
							if (!file_exists(STREAMS_TMP_PATH . 'stream_' . intval($rStreamID))) {
							} else {
								$rRow = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rStreamID)))['info'];
								$rArchiveInfo[$rStreamID] = intval($rRow['tv_archive_duration']);
							}
						}
					} else {
						$db->query('SELECT `id`, `tv_archive_duration` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

						if (0 >= $db->num_rows()) {
						} else {
							foreach ($db->get_rows() as $rRow) {
								$rArchiveInfo[$rRow['id']] = intval($rRow['tv_archive_duration']);
							}
						}
					}

					foreach ($rStreamIDs as $rStreamID) {
						if (!file_exists(EPG_PATH . 'stream_' . intval($rStreamID))) {
						} else {
							$rRows = igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStreamID));

							foreach ($rRows as $rEPGData) {
								$rNowPlaying = $rHasArchive = 0;
								$rEPGData['start_timestamp'] = $rEPGData['start'];
								$rEPGData['stop_timestamp'] = $rEPGData['end'];

								if (!($rEPGData['start_timestamp'] <= time() && time() <= $rEPGData['stop_timestamp'])) {
								} else {
									$rNowPlaying = 1;
								}

								if (!(!empty($rArchiveInfo[$rStreamID]) && $rEPGData['stop_timestamp'] < time() && strtotime('-' . $rArchiveInfo[$rStreamID] . ' days') <= $rEPGData['stop_timestamp'])) {
								} else {
									$rHasArchive = 1;
								}

								$rEPGData['now_playing'] = $rNowPlaying;
								$rEPGData['has_archive'] = $rHasArchive;
								$rEPGData['title'] = base64_encode($rEPGData['title']);
								$rEPGData['description'] = base64_encode($rEPGData['description']);
								$rEPGData['start'] = date('Y-m-d H:i:s', $rEPGData['start_timestamp']);
								$rEPGData['end'] = date('Y-m-d H:i:s', $rEPGData['stop_timestamp']);

								if ($rMulti) {
									$output['epg_listings'][$rStreamID][] = $rEPGData;
								} else {
									$output['epg_listings'][] = $rEPGData;
								}
							}
						}
					}
				}
			}

			break;

		case 'get_short_epg':
			$output['epg_listings'] = array();

			if (empty(StreamingUtilities::$rRequest['stream_id'])) {
			} else {
				$rLimit = (empty(StreamingUtilities::$rRequest['limit']) ? 4 : intval(StreamingUtilities::$rRequest['limit']));

				if (is_numeric(StreamingUtilities::$rRequest['stream_id']) && !isset(StreamingUtilities::$rRequest['multi'])) {
					$rMulti = false;
					$rStreamIDs = array(intval(StreamingUtilities::$rRequest['stream_id']));
				} else {
					$rMulti = true;
					$rStreamIDs = array_map('intval', explode(',', StreamingUtilities::$rRequest['stream_id']));
				}

				if (0 >= count($rStreamIDs)) {
				} else {
					$rTime = time();

					foreach ($rStreamIDs as $rStreamID) {
						if (!file_exists(EPG_PATH . 'stream_' . intval($rStreamID))) {
						} else {
							$rRows = igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStreamID));

							foreach ($rRows as $rRow) {
								if (!($rRow['start'] <= $rTime && $rTime <= $rRow['end'] || $rTime <= $rRow['start'])) {
								} else {
									$rRow['start_timestamp'] = $rRow['start'];
									$rRow['stop_timestamp'] = $rRow['end'];
									$rRow['title'] = base64_encode($rRow['title']);
									$rRow['description'] = base64_encode($rRow['description']);
									$rRow['start'] = date('Y-m-d H:i:s', $rRow['start']);


									$rRow['stop'] = date('Y-m-d H:i:s', $rRow['end']);

									if ($rMulti) {
										$output['epg_listings'][$rStreamID][] = $rRow;
									} else {
										$output['epg_listings'][] = $rRow;
									}

									if ($rLimit > count($output['epg_listings'])) {
									} else {
										break;
									}
								}
							}
						}
					}
				}
			}

			break;

		case 'get_live_streams':
			$rCategoryIDSearch = (empty(StreamingUtilities::$rRequest['category_id']) ? null : intval(StreamingUtilities::$rRequest['category_id']));
			$rLiveNum = 0;
			$rUserInfo['live_ids'] = array_merge($rUserInfo['live_ids'], $rUserInfo['radio_ids']);

			if (!empty($rExtract['items_per_page'])) {
				$rUserInfo['live_ids'] = array_slice($rUserInfo['live_ids'], $rExtract['offset'], $rExtract['items_per_page']);
			}

			$rUserInfo['live_ids'] = StreamingUtilities::sortChannels($rUserInfo['live_ids']);

			if (!StreamingUtilities::$rCached) {
				$rChannels = array();

				if (count($rUserInfo['live_ids']) > 0) {
					$rWhereV = $rWhere = array();

					if (!empty($rCategoryIDSearch)) {
						$rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
						$rWhereV[] = $rCategoryIDSearch;
					}

					$rWhere[] = '`t1`.`id` IN (' . implode(',', $rUserInfo['live_ids']) . ')';
					$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);

					if (StreamingUtilities::$rSettings['channel_number_type'] != 'manual') {
						$rOrder = 'FIELD(`t1`.`id`,' . implode(',', $rUserInfo['live_ids']) . ')';
					} else {
						$rOrder = '`order`';
					}

					StreamingUtilities::$db->query('SELECT t1.id,t1.epg_id,t1.added,t1.allow_record,t1.year,t1.channel_id,t1.movie_properties,t1.stream_source,t1.tv_archive_server_id,t1.vframes_server_id,t1.tv_archive_duration,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t1.series_no,t1.direct_source,t2.type_output,t1.target_container,t2.live,t1.rtmp_output,t1.order,t2.type_key FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type ' . $rWhereString . ' ORDER BY ' . $rOrder . ';', ...$rWhereV);
					$rChannels = StreamingUtilities::$db->get_rows();
				}
			} else {
				$rChannels = $rUserInfo['live_ids'];
			}

			foreach ($rChannels as $rChannel) {
				if (StreamingUtilities::$rCached) {
					$rChannel = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rChannel)))['info'];
				}

				if (in_array($rChannel['type_key'], array('live', 'created_live', 'radio_streams'))) {
					$rCategoryIDs = json_decode($rChannel['category_id'], true);

					foreach ($rCategoryIDs as $rCategoryID) {
						if (!$rCategoryIDSearch || $rCategoryIDSearch == $rCategoryID) {
							$rStreamIcon = (StreamingUtilities::validateImage($rChannel['stream_icon']) ?: '');
							$rTVArchive = (!empty($rChannel['tv_archive_server_id']) && !empty($rChannel['tv_archive_duration']) ? 1 : 0);

							if (StreamingUtilities::$rSettings['api_redirect']) {
								$rEncData = 'live/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'];
								$rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);

								if (StreamingUtilities::$rSettings['cloudflare'] && StreamingUtilities::$rSettings['api_container'] == 'ts') {
									$rURL = $rDomainName . 'play/' . $rToken;
								} else {
									$rURL = $rDomainName . 'play/' . $rToken . '/' . StreamingUtilities::$rSettings['api_container'];
								}
							} else {
								$rURL = '';
							}

							if ($rChannel['vframes_server_id']) {
								$rEncData = 'thumb/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'];
								$rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
								$rThumbURL = $rDomainName . 'play/' . $rToken;
							} else {
								$rThumbURL = '';
							}

							$output[] = array('num' => ++$rLiveNum, 'name' => $rChannel['stream_display_name'], 'stream_type' => $rChannel['type_key'], 'stream_id' => (int) $rChannel['id'], 'stream_icon' => $rStreamIcon, 'epg_channel_id' => $rChannel['channel_id'], 'added' => ($rChannel['added'] ?: ''), 'custom_sid' => strval($rChannel['custom_sid']), 'tv_archive' => $rTVArchive, 'direct_source' => $rURL, 'tv_archive_duration' => ($rTVArchive ? intval($rChannel['tv_archive_duration']) : 0), 'category_id' => strval($rCategoryID), 'category_ids' => $rCategoryIDs, 'thumbnail' => $rThumbURL);
						}

						if (!($rCategoryIDSearch || StreamingUtilities::$rSettings['show_category_duplicates'])) {
							break;
						}
					}
				}
			}

			break;

		case 'get_vod_info':
			$output['info'] = array();

			if (!empty(StreamingUtilities::$rRequest['vod_id'])) {
				$rVODID = intval(StreamingUtilities::$rRequest['vod_id']);

				if (StreamingUtilities::$rCached) {
					$rRow = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rVODID)))['info'];
				} else {
					StreamingUtilities::$db->query('SELECT * FROM `streams` WHERE `id` = ?', $rVODID);
					$rRow = StreamingUtilities::$db->get_row();
				}

				if ($rRow) {
					if (StreamingUtilities::$rSettings['api_redirect']) {
						$rEncData = 'movie/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rRow['id'] . '/' . $rRow['target_container'];
						$rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
						$rURL = $rDomainName . 'play/' . $rToken;
					} else {
						$rURL = '';
					}

					$output['info'] = json_decode($rRow['movie_properties'], true);
					$output['info']['tmdb_id'] = intval($output['info']['tmdb_id']);
					$output['info']['episode_run_time'] = intval($output['info']['episode_run_time']);
					$output['info']['releasedate'] = $output['info']['release_date'];
					$output['info']['cover_big'] = StreamingUtilities::validateImage($output['info']['cover_big']);
					$output['info']['movie_image'] = StreamingUtilities::validateImage($output['info']['movie_image']);
					$output['info']['rating'] = number_format($output['info']['rating'], 2) + 0;

					if (count($output['info']['backdrop_path']) > 0) {
						foreach (range(0, count($output['info']['backdrop_path']) - 1) as $i) {
							$output['info']['backdrop_path'][$i] = StreamingUtilities::validateImage($output['info']['backdrop_path'][$i]);
						}
					}

					$output['info']['subtitles'] = array();

					if (is_array($output['info']['subtitle'])) {
						$i = 0;

						foreach ($output['info']['subtitle'] as $rSubtitle) {
							$output['info']['subtitles'][] = array('index' => $i, 'language' => ($rSubtitle['tags']['language'] ?: null), 'title' => ($rSubtitle['tags']['title'] ?: null));
							$i++;
						}
					}

					foreach (array('audio', 'video', 'subtitle') as $rKey) {
						if (isset($output['info'][$rKey])) {
							unset($output['info'][$rKey]);
						}
					}
					$output['movie_data'] = array('stream_id' => (int) $rRow['id'], 'name' => StreamingUtilities::formatTitle($rRow['stream_display_name'], $rRow['year']), 'title' => $rRow['stream_display_name'], 'year' => $rRow['year'], 'added' => ($rRow['added'] ?: ''), 'category_id' => strval(json_decode($rRow['category_id'], true)[0]), 'category_ids' => json_decode($rRow['category_id'], true), 'container_extension' => $rRow['target_container'], 'custom_sid' => strval($rRow['custom_sid']), 'direct_source' => $rURL);
				}
			}

			break;

		case 'get_vod_streams':
			$rCategoryIDSearch = (empty(StreamingUtilities::$rRequest['category_id']) ? null : intval(StreamingUtilities::$rRequest['category_id']));
			$rMovieNum = 0;

			if (!empty($rExtract['items_per_page'])) {
				$rUserInfo['vod_ids'] = array_slice($rUserInfo['vod_ids'], $rExtract['offset'], $rExtract['items_per_page']);
			}

			$rUserInfo['vod_ids'] = StreamingUtilities::sortChannels($rUserInfo['vod_ids']);

			if (!StreamingUtilities::$rCached) {
				$rChannels = array();

				if (count($rUserInfo['vod_ids']) > 0) {
					$rWhereV = $rWhere = array();

					if (!empty($rCategoryIDSearch)) {
						$rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
						$rWhereV[] = $rCategoryIDSearch;
					}

					$rWhere[] = '`t1`.`id` IN (' . implode(',', $rUserInfo['vod_ids']) . ')';
					$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);

					if (StreamingUtilities::$rSettings['channel_number_type'] != 'manual') {
						$rOrder = 'FIELD(`t1`.`id`,' . implode(',', $rUserInfo['vod_ids']) . ')';
					} else {
						$rOrder = '`order`';
					}

					StreamingUtilities::$db->query('SELECT t1.id,t1.epg_id,t1.added,t1.allow_record,t1.year,t1.channel_id,t1.movie_properties,t1.stream_source,t1.tv_archive_server_id,t1.vframes_server_id,t1.tv_archive_duration,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t1.series_no,t1.direct_source,t2.type_output,t1.target_container,t2.live,t1.rtmp_output,t1.order,t2.type_key FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type ' . $rWhereString . ' ORDER BY ' . $rOrder . ';', ...$rWhereV);
					$rChannels = StreamingUtilities::$db->get_rows();
				}
			} else {
				$rChannels = $rUserInfo['vod_ids'];
			}

			foreach ($rChannels as $rChannel) {
				if (StreamingUtilities::$rCached) {
					$rChannel = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rChannel)))['info'];
				}
				if (in_array($rChannel['type_key'], array('movie'))) {
					$rProperties = json_decode($rChannel['movie_properties'], true);
					$rCategoryIDs = json_decode($rChannel['category_id'], true);

					foreach ($rCategoryIDs as $rCategoryID) {

						if (!$rCategoryIDSearch || $rCategoryIDSearch == $rCategoryID) {
							if (StreamingUtilities::$rSettings['api_redirect']) {
								$rEncData = 'movie/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'] . '/' . $rChannel['target_container'];
								$rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
								$rURL = $rDomainName . 'play/' . $rToken;
							} else {
								$rURL = '';
							}

							$output[] = array('num' => ++$rMovieNum, 'name' => StreamingUtilities::formatTitle($rChannel['stream_display_name'], $rChannel['year']), 'title' => $rChannel['stream_display_name'], 'year' => $rChannel['year'], 'stream_type' => $rChannel['type_key'], 'stream_id' => (int) $rChannel['id'], 'stream_icon' => (StreamingUtilities::validateImage($rProperties['movie_image']) ?: ''), 'rating' => number_format($rProperties['rating'], 1) + 0, 'rating_5based' => number_format($rProperties['rating'] * 0.5, 1) + 0, 'added' => ($rChannel['added'] ?: ''), 'plot' => $rProperties['plot'], 'cast' => $rProperties['cast'], 'director' => $rProperties['director'], 'genre' => $rProperties['genre'], 'release_date' => $rProperties['release_date'], 'youtube_trailer' => $rProperties['youtube_trailer'], 'episode_run_time' => $rProperties['episode_run_time'], 'category_id' => strval($rCategoryID), 'category_ids' => $rCategoryIDs, 'container_extension' => $rChannel['target_container'], 'custom_sid' => strval($rChannel['custom_sid']), 'direct_source' => $rURL);
						}

						if (!($rCategoryIDSearch || StreamingUtilities::$rSettings['show_category_duplicates'])) {
							break;
						}
					}
				}
			}
			break;

		default:
			$output['user_info'] = [
				'username' => $rUserInfo['username'],
				'password' => $rUserInfo['password'],
				'message' => StreamingUtilities::$rSettings['message_of_day'],
				'auth' => 1,
				'status' => 'Active',
				'exp_date' => $rUserInfo['exp_date'],
				'is_trial' => $rUserInfo['is_trial'],
				'created_at' => $rUserInfo['created_at'],
				'max_connections' => $rUserInfo['max_connections'],
				'allowed_output_formats' => getOutputFormats($rUserInfo['allowed_outputs'])
			];

			if (!empty($token)) {
				$output['user_info']['token'] = $token;
			}

			$output['server_info'] = [
				'xui' => true,
				'version' => XC_VM_VERSION,
				'url' => $rDomain,
				'port' => StreamingUtilities::$rServers[SERVER_ID]['http_broadcast_port'],
				'https_port' => StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'],
				'server_protocol' => StreamingUtilities::$rServers[SERVER_ID]['server_protocol'],
				'rtmp_port' => StreamingUtilities::$rServers[SERVER_ID]['rtmp_port'],
				'timestamp_now' => time(),
				'time_now' => date('Y-m-d H:i:s'),
				'timezone' => StreamingUtilities::$rSettings['force_epg_timezone'] ? 'UTC' : StreamingUtilities::$rSettings['default_timezone']
			];
			break;
	}
	echo json_encode($output);
	exit();
} else {
	StreamingUtilities::checkBruteforce(null, null, $rUsername);
	generateError('INVALID_CREDENTIALS');
}

function getOutputFormats($rFormats) {
	$rFormatArray = array(1 => 'm3u8', 2 => 'ts', 3 => 'rtmp');
	$rReturn = array();

	foreach ($rFormats as $rFormat) {
		$rReturn[] = $rFormatArray[$rFormat];
	}

	return $rReturn;
}

function shutdown() {
	global $rDeny;

	if ($rDeny) {
		StreamingUtilities::checkFlood();
	}

	if (is_object(StreamingUtilities::$db)) {
		StreamingUtilities::$db->close_mysql();
	}
}
