<?php

register_shutdown_function('shutdown');
require './init.php';
$rDeny = true;

if (!CoreUtilities::$rSettings['disable_enigma2']) {
} else {
	$rDeny = false;
	generateError('E2_DISABLED');
}

$rUsername = CoreUtilities::$rRequest['username'];
$rPassword = CoreUtilities::$rRequest['password'];
$rType = (!empty(CoreUtilities::$rRequest['type']) ? CoreUtilities::$rRequest['type'] : null);
$rCatID = (!empty(CoreUtilities::$rRequest['cat_id']) ? intval(CoreUtilities::$rRequest['cat_id']) : null);
$sCatID = (!empty(CoreUtilities::$rRequest['scat_id']) ? intval(CoreUtilities::$rRequest['scat_id']) : null);
$rSeriesID = (!empty(CoreUtilities::$rRequest['series_id']) ? intval(CoreUtilities::$rRequest['series_id']) : null);
$rSeason = (!empty(CoreUtilities::$rRequest['season']) ? intval(CoreUtilities::$rRequest['season']) : null);
$rProtocol = (stripos($_SERVER['SERVER_PROTOCOL'], 'https') === 0 ? 'https://' : 'http://');
$rURL = (!empty($_SERVER['HTTP_HOST']) ? $rProtocol . $_SERVER['HTTP_HOST'] . '/' : CoreUtilities::$rServers[SERVER_ID]['site_url']);
ini_set('memory_limit', -1);

if (empty($rUsername) || empty($rPassword)) {
	generateError('NO_CREDENTIALS');
}

if ($rUserInfo = CoreUtilities::getUserInfo(null, $rUsername, $rPassword, true, false)) {
	$rDeny = false;
	$db = new Database($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
	CoreUtilities::$db = &$db;
	CoreUtilities::checkAuthFlood($rUserInfo);
	$rLiveCategories = CoreUtilities::getCategories('live');
	$rVODCategories = CoreUtilities::getCategories('movie');
	$rSeriesCategories = CoreUtilities::getCategories('series');
	$rLiveStreams = array();
	$rVODStreams = array();

	if (CoreUtilities::$rCached) {
		$rChannels = $rUserInfo['channel_ids'];
	} else {
		$rChannels = array();

		if (0 >= count($rUserInfo['channel_ids'])) {
		} else {
			$rWhereV = $rWhere = array();
			$rWhere[] = '`id` IN (' . implode(',', $rUserInfo['channel_ids']) . ')';
			$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
			$rOrder = 'FIELD(id,' . implode(',', $rUserInfo['channel_ids']) . ')';
			CoreUtilities::$db->query('SELECT t1.id,t1.epg_id,t1.added,t1.allow_record,t1.year,t1.channel_id,t1.movie_properties,t1.stream_source,t1.tv_archive_server_id,t1.vframes_server_id,t1.tv_archive_duration,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t1.series_no,t1.direct_source,t2.type_output,t1.target_container,t2.live,t1.rtmp_output,t1.order,t2.type_key FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type ' . $rWhereString . ' ORDER BY ' . $rOrder . ';', ...$rWhereV);
			$rChannels = CoreUtilities::$db->get_rows();
		}
	}

	$rUserInfo['channel_ids'] = CoreUtilities::sortChannels($rUserInfo['channel_ids']);

	foreach ($rChannels as $rChannel) {
		if (!CoreUtilities::$rCached) {
		} else {
			$rChannel = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rChannel)))['info'];
		}

		if ($rChannel['live'] == 0) {
			$rVODStreams[] = $rChannel;
		} else {
			$rLiveStreams[] = $rChannel;
		}
	}
	unset($rChannels);

	switch ($rType) {
		case 'get_live_categories':
			$rXML = new SimpleXMLExtended('<items/>');
			$rXML->addChild('playlist_name', 'Live [ ' . CoreUtilities::$rSettings['server_name'] . ' ]');
			$rCategory = $rXML->addChild('category');
			$rCategory->addChild('category_id', 1);
			$rCategory->addChild('category_title', 'Live [ ' . CoreUtilities::$rSettings['server_name'] . ' ]');
			$rChannels = $rXML->addChild('channel');
			$rChannels->addChild('title', base64_encode('All'));
			$rChannels->addChild('description', base64_encode('Live Streams Category [ ALL ]'));
			$rChannels->addChild('category_id', 0);
			$rCData = $rChannels->addChild('playlist_url');
			$rCData->addCData($rURL . 'enigma2?username=' . $rUsername . '&password=' . $rPassword . '&type=get_live_streams&cat_id=0' . $rCategory['id']);

			foreach ($rLiveCategories as $rCategoryID => $rCategory) {
				$rChannels = $rXML->addChild('channel');
				$rChannels->addChild('title', base64_encode($rCategory['category_name']));
				$rChannels->addChild('description', base64_encode('Live Streams Category'));
				$rChannels->addChild('category_id', $rCategory['id']);
				$rCData = $rChannels->addChild('playlist_url');
				$rCData->addCData($rURL . 'enigma2?username=' . $rUsername . '&password=' . $rPassword . '&type=get_live_streams&cat_id=' . $rCategory['id']);
			}
			header('Content-Type: application/xml; charset=utf-8');
			echo $rXML->asXML();

			break;

		case 'get_vod_categories':
			$rXML = new SimpleXMLExtended('<items/>');
			$rXML->addChild('playlist_name', 'Movie [ ' . CoreUtilities::$rSettings['server_name'] . ' ]');
			$rCategory = $rXML->addChild('category');
			$rCategory->addChild('category_id', 1);
			$rCategory->addChild('category_title', 'Movie [ ' . CoreUtilities::$rSettings['server_name'] . ' ]');
			$rChannels = $rXML->addChild('channel');
			$rChannels->addChild('title', base64_encode('All'));
			$rChannels->addChild('description', base64_encode('Movie Streams Category [ ALL ]'));
			$rChannels->addChild('category_id', 0);
			$rCData = $rChannels->addChild('playlist_url');
			$rCData->addCData($rURL . 'enigma2?username=' . $rUsername . '&password=' . $rPassword . '&type=get_vod_streams&cat_id=0' . $rCategory['id']);

			foreach ($rVODCategories as $movie_category_id => $rCategory) {
				$rChannels = $rXML->addChild('channel');
				$rChannels->addChild('title', base64_encode($rCategory['category_name']));
				$rChannels->addChild('description', base64_encode('Movie Streams Category'));
				$rChannels->addChild('category_id', $rCategory['id']);
				$rCData = $rChannels->addChild('playlist_url');
				$rCData->addCData($rURL . 'enigma2?username=' . $rUsername . '&password=' . $rPassword . '&type=get_vod_streams&cat_id=' . $rCategory['id']);
			}
			header('Content-Type: application/xml; charset=utf-8');
			echo $rXML->asXML();

			break;

		case 'get_series_categories':
			$rXML = new SimpleXMLExtended('<items/>');
			$rXML->addChild('playlist_name', 'SubCategory [ ' . CoreUtilities::$rSettings['server_name'] . ' ]');
			$rCategory = $rXML->addChild('category');
			$rCategory->addChild('category_id', 1);
			$rCategory->addChild('category_title', 'SubCategory [ ' . CoreUtilities::$rSettings['server_name'] . ' ]');
			$rChannels = $rXML->addChild('channel');
			$rChannels->addChild('title', base64_encode('All'));
			$rChannels->addChild('description', base64_encode('TV Series Category [ ALL ]'));
			$rChannels->addChild('category_id', 0);
			$rCData = $rChannels->addChild('playlist_url');
			$rCData->addCData($rURL . 'enigma2?username=' . $rUsername . '&password=' . $rPassword . '&type=get_series&cat_id=0' . $rCategory['id']);

			foreach ($rSeriesCategories as $movie_category_id => $rCategory) {
				$rChannels = $rXML->addChild('channel');
				$rChannels->addChild('title', base64_encode($rCategory['category_name']));
				$rChannels->addChild('description', base64_encode('TV Series Category'));
				$rChannels->addChild('category_id', $rCategory['id']);
				$rCData = $rChannels->addChild('playlist_url');
				$rCData->addCData($rURL . 'enigma2?username=' . $rUsername . '&password=' . $rPassword . '&type=get_series&cat_id=' . $rCategory['id']);
			}
			header('Content-Type: application/xml; charset=utf-8');
			echo $rXML->asXML();

			break;

		case 'get_series':
			if (!(isset($rCatID) || is_null($rCatID) || isset($sCatID) || is_null($sCatID))) {
			} else {
				$rCategoryID = (is_null($rCatID) ? null : $rCatID);

				if (!is_null($rCategoryID)) {
				} else {
					$rCategoryID = (is_null($sCatID) ? null : $sCatID);
					$rCatID = $sCatID;
				}

				$rCategoryName = (!empty($rSeriesCategories[$rCatID]) ? $rSeriesCategories[$rCatID]['category_name'] : 'ALL');
				$rXML = new SimpleXMLExtended('<items/>');
				$rXML->addChild('playlist_name', 'TV Series [ ' . $rCategoryName . ' ]');
				$rCategory = $rXML->addChild('category');
				$rCategory->addChild('category_id', 1);
				$rCategory->addChild('category_title', 'TV Series [ ' . $rCategoryName . ' ]');

				if (0 >= count($rUserInfo['series_ids'])) {
				} else {
					if (CoreUtilities::$rSettings['vod_sort_newest']) {
						$db->query('SELECT * FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rUserInfo['series_ids'])) . ') ORDER BY `last_modified` DESC;');
					} else {
						$db->query('SELECT * FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rUserInfo['series_ids'])) . ') ORDER BY FIELD(`id`,' . implode(',', $rUserInfo['series_ids']) . ') ASC;');
					}

					$rSeries = $db->get_rows(true, 'id');

					foreach ($rSeries as $rSeriesID => $rSeriesInfo) {
						foreach (json_decode($rSeriesInfo['category_id'], true) as $rCategoryIDSearch) {
							if ($rCategoryID && $rCategoryID != $rCategoryIDSearch) {
							} else {
								$rChannels = $rXML->addChild('channel');
								$rChannels->addChild('title', base64_encode($rSeriesInfo['title']));
								$rChannels->addChild('description', '');
								$rChannels->addChild('category_id', $rSeriesID);
								$rCData = $rChannels->addChild('playlist_url');
								$rCData->addCData($rURL . 'enigma2?username=' . $rUsername . '&password=' . $rPassword . '&type=get_seasons&series_id=' . $rSeriesID);
							}

							if ($rCategoryID) {
							} else {
								break;
							}
						}
					}
				}

				header('Content-Type: application/xml; charset=utf-8');
				echo $rXML->asXML();
			}

			break;

		case 'get_seasons':
			if (!isset($rSeriesID)) {
			} else {
				$db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $rSeriesID);
				$rSeriesInfo = $db->get_row();
				$rCategoryName = $rSeriesInfo['title'];
				$rXML = new SimpleXMLExtended('<items/>');
				$rXML->addChild('playlist_name', 'TV Series [ ' . $rCategoryName . ' ]');
				$rCategory = $rXML->addChild('category');
				$rCategory->addChild('category_id', 1);
				$rCategory->addChild('category_title', 'TV Series [ ' . $rCategoryName . ' ]');
				$db->query('SELECT * FROM `streams_episodes` t1 INNER JOIN `streams` t2 ON t2.id=t1.stream_id WHERE t1.series_id = ? ORDER BY t1.season_num ASC, t1.episode_num ASC', $rSeriesID);
				$rRows = $db->get_rows(true, 'season_num', false);

				foreach (array_keys($rRows) as $rSeasonNum) {
					$rChannels = $rXML->addChild('channel');
					$rChannels->addChild('title', base64_encode('Season ' . $rSeasonNum));
					$rChannels->addChild('description', '');
					$rChannels->addChild('category_id', $rSeasonNum);
					$rCData = $rChannels->addChild('playlist_url');
					$rCData->addCData($rURL . 'enigma2?username=' . $rUsername . '&password=' . $rPassword . '&type=get_series_streams&series_id=' . $rSeriesID . '&season=' . $rSeasonNum);
				}
				header('Content-Type: application/xml; charset=utf-8');
				echo $rXML->asXML();
			}

			break;

		case 'get_series_streams':
			if (!(isset($rSeriesID) && isset($rSeason))) {
			} else {
				$db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $rSeriesID);
				$rSeriesInfo = $db->get_row();
				$rXML = new SimpleXMLExtended('<items/>');
				$rXML->addChild('playlist_name', 'TV Series [ ' . $rSeriesInfo['title'] . ' Season ' . $rSeason . ' ]');
				$rCategory = $rXML->addChild('category');
				$rCategory->addChild('category_id', 1);
				$rCategory->addChild('category_title', 'TV Series [ ' . $rSeriesInfo['title'] . ' Season ' . $rSeason . ' ]');
				$db->query('SELECT t2.direct_source,t2.stream_source,t2.target_container,t2.id,t1.series_id,t1.season_num FROM `streams_episodes` t1 INNER JOIN `streams` t2 ON t2.id=t1.stream_id WHERE t1.series_id = ? AND t1.season_num = ? ORDER BY  t1.episode_num ASC', $rSeriesID, $rSeason);
				$rSeriesEpisodes = $db->get_rows();
				$rEpisodeNum = 0;

				foreach ($rSeriesEpisodes as $rEpisode) {
					$rChannels = $rXML->addChild('channel');
					$rChannels->addChild('title', base64_encode('Episode ' . sprintf('%02d', ++$rEpisodeNum)));
					$rDesc = '';
					$rDescChannel = $rChannels->addChild('desc_image');
					$rDescChannel->addCData(CoreUtilities::validateImage($rSeriesInfo['cover']));
					$rChannels->addChild('description', base64_encode($rDesc));
					$rChannels->addChild('category_id', $rCatID);
					$rCDataURL = $rChannels->addChild('stream_url');
					$rEncData = 'movie/' . $rUsername . '/' . $rPassword . '/' . $rEpisode['id'] . '/' . $rEpisode['target_container'];
					$rToken = CoreUtilities::encryptData($rEncData, CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
					$rSource = $rURL . 'play/' . $rToken;
					$rCDataURL->addCData($rSource);
				}
				header('Content-Type: application/xml; charset=utf-8');
				echo $rXML->asXML();
			}

			break;

		case 'get_live_streams':
			if (!(isset($rCatID) || is_null($rCatID))) {
			} else {
				$rCategoryID = (is_null($rCatID) ? null : $rCatID);
				$rXML = new SimpleXMLExtended('<items/>');
				$rXML->addChild('playlist_name', 'Live [ ' . CoreUtilities::$rSettings['server_name'] . ' ]');
				$rCategory = $rXML->addChild('category');
				$rCategory->addChild('category_id', 1);
				$rCategory->addChild('category_title', 'Live [ ' . CoreUtilities::$rSettings['server_name'] . ' ]');

				foreach ($rLiveStreams as $rStream) {
					if ($rCategoryID && !in_array($rCategoryID, json_decode($rStream['category_id'], true))) {
					} else {
						$rChannelEPGs = array();

						if (!file_exists(EPG_PATH . 'stream_' . intval($rStream['id']))) {
						} else {
							foreach (igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStream['id'])) as $rRow) {
								if ($rRow['end'] >= time()) {
									$rChannelEPGs[] = $rRow;

									if (2 > count($rChannelEPGs)) {
									} else {
										break;
									}
								}
							}
						}

						$rDesc = '';
						$rShortEPG = '';
						$i = 0;

						foreach ($rChannelEPGs as $rRow) {
							$rDesc .= '[' . date('H:i', $rRow['start']) . '] ' . $rRow['title'] . "\n" . '( ' . $rRow['description'] . ')' . "\n";

							if ($i != 0) {
							} else {
								$rShortEPG = '[' . date('H:i', $rRow['start']) . ' - ' . date('H:i', $rRow['end']) . '] + ' . round(($rRow['end'] - time()) / 60, 1) . ' min   ' . $rRow['title'];
								$i++;
							}
						}
					}

					foreach (json_decode($rStream['category_id'], true) as $rCategoryIDSearch) {
						if ($rCategoryID && $rCategoryID != $rCategoryIDSearch) {
						} else {
							$rChannels = $rXML->addChild('channel');
							$rChannels->addChild('title', base64_encode($rStream['stream_display_name'] . ' ' . $rShortEPG));
							$rChannels->addChild('description', base64_encode($rDesc));
							$rDescChannel = $rChannels->addChild('desc_image');
							$rDescChannel->addCData(CoreUtilities::validateImage($rStream['stream_icon']));
							$rChannels->addChild('category_id', $rCategoryIDSearch);
							$rCData = $rChannels->addChild('stream_url');
							$rEncData = 'live/' . $rUsername . '/' . $rPassword . '/' . $rStream['id'];
							$rToken = CoreUtilities::encryptData($rEncData, CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
							$rSource = $rURL . 'play/' . $rToken;
							$rCData->addCData($rSource);
						}

						if ($rCategoryID) {
						} else {
							break;
						}
					}
				}
				header('Content-Type: application/xml; charset=utf-8');
				echo $rXML->asXML();
			}

			break;

		case 'get_vod_streams':
			if (!(isset($rCatID) || is_null($rCatID))) {
			} else {
				$rCategoryID = (is_null($rCatID) ? null : $rCatID);
				$rXML = new SimpleXMLExtended('<items/>');
				$rXML->addChild('playlist_name', 'Movie [ ' . CoreUtilities::$rSettings['server_name'] . ' ]');
				$rCategory = $rXML->addChild('category');
				$rCategory->addChild('category_id', 1);
				$rCategory->addChild('category_title', 'Movie [ ' . CoreUtilities::$rSettings['server_name'] . ' ]');

				foreach ($rVODStreams as $rStream) {
					foreach (json_decode($rStream['category_id'], true) as $rCategoryIDSearch) {
						if ($rCategoryID && $rCategoryID != $rCategoryIDSearch) {
						} else {
							$rProperties = json_decode($rStream['movie_properties'], true);
							$rChannels = $rXML->addChild('channel');
							$rChannels->addChild('title', base64_encode($rStream['stream_display_name']));
							$rDesc = '';

							if (!$rProperties) {
							} else {
								foreach ($rProperties as $rKey => $rProperty) {
									if ($rKey != 'movie_image') {
										$rDesc .= strtoupper($rKey) . ': ' . $rProperty . "\n";
									}
								}
							}

							$rDescChannel = $rChannels->addChild('desc_image');
							$rDescChannel->addCData(CoreUtilities::validateImage($rProperties['movie_image']));
							$rChannels->addChild('description', base64_encode($rDesc));
							$rChannels->addChild('category_id', $rCategoryIDSearch);
							$rCDataURL = $rChannels->addChild('stream_url');
							$rEncData = 'movie/' . $rUsername . '/' . $rPassword . '/' . $rStream['id'] . '/' . $rStream['target_container'];
							$rToken = CoreUtilities::encryptData($rEncData, CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
							$rSource = $rURL . 'play/' . $rToken;
							$rCDataURL->addCData($rSource);
						}

						if ($rCategoryID) {
						} else {
							break;
						}
					}
				}
				header('Content-Type: application/xml; charset=utf-8');
				echo $rXML->asXML();
			}

			break;

		default:
			$rXML = new SimpleXMLExtended('<items/>');
			$rXML->addChild('playlist_name', CoreUtilities::$rSettings['server_name']);
			$rCategory = $rXML->addChild('category');
			$rCategory->addChild('category_id', 1);
			$rCategory->addChild('category_title', CoreUtilities::$rSettings['server_name']);

			if (empty($rLiveStreams)) {
			} else {
				class SimpleXMLExtended extends SimpleXMLElement {
					public function addCData($rCData) {
						$rNode = dom_import_simplexml($this);
						$rRowner = $rNode->ownerDocument;
						$rNode->appendChild($rRowner->createCDATASection($rCData));
					}
				}

				$rChannels = $rXML->addChild('channel');
				$rChannels->addChild('title', base64_encode('Live Streams'));
				$rChannels->addChild('description', base64_encode('Live Streams Category'));
				$rChannels->addChild('category_id', 0);
				$rCData = $rChannels->addChild('playlist_url');
				$rCData->addCData($rURL . 'enigma2?username=' . $rUsername . '&password=' . $rPassword . '&type=get_live_categories');
			}

			if (empty($rVODStreams)) {
			} else {
				$rChannels = $rXML->addChild('channel');
				$rChannels->addChild('title', base64_encode('VOD'));
				$rChannels->addChild('description', base64_encode('Video On Demand Category'));
				$rChannels->addChild('category_id', 1);
				$rCData = $rChannels->addChild('playlist_url');
				$rCData->addCData($rURL . 'enigma2?username=' . $rUsername . '&password=' . $rPassword . '&type=get_vod_categories');
			}

			$rChannels = $rXML->addChild('channel');
			$rChannels->addChild('title', base64_encode('TV Series'));
			$rChannels->addChild('description', base64_encode('TV Series Category'));
			$rChannels->addChild('category_id', 2);
	}
	$rCData = $rChannels->addChild('playlist_url');
	$rCData->addCData($rURL . 'enigma2?username=' . $rUsername . '&password=' . $rPassword . '&type=get_series_categories');
	header('Content-Type: application/xml; charset=utf-8');
	echo $rXML->asXML();
} else {
	CoreUtilities::checkBruteforce(null, null, $rUsername);
	generateError('INVALID_CREDENTIALS');
}

function shutdown() {
	global $db;
	global $rDeny;

	if (!$rDeny) {
	} else {
		CoreUtilities::checkFlood();
	}

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}
