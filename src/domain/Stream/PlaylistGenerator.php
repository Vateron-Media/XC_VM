<?php

class PlaylistGenerator {
	public static function generate($db, $rSettings, $rServers, $rCategories, $rCached, $rUserInfo, $rDeviceKey, $rOutputKey = 'ts', $rTypeKey = null, $rNoCache = false, $rProxy = false) {
		if (empty($rDeviceKey)) {
			return false;
		}

		if ($rOutputKey == 'mpegts') {
			$rOutputKey = 'ts';
		}
		if ($rOutputKey == 'hls') {
			$rOutputKey = 'm3u8';
		}

		if (empty($rOutputKey)) {
			$db->query('SELECT t1.output_ext FROM `output_formats` t1 INNER JOIN `output_devices` t2 ON t2.default_output = t1.access_output_id AND `device_key` = ?', $rDeviceKey);
		} else {
			$db->query('SELECT t1.output_ext FROM `output_formats` t1 WHERE `output_key` = ?', $rOutputKey);
		}

		if ($db->num_rows() <= 0) {
			return false;
		}

		$rCacheName = $rUserInfo['id'] . '_' . $rDeviceKey . '_' . $rOutputKey . '_' . implode('_', ($rTypeKey ?: array()));
		$rOutputExt = $db->get_col();
		$rEncryptPlaylist = ($rUserInfo['is_restreamer'] ? $rSettings['encrypt_playlist_restreamer'] : $rSettings['encrypt_playlist']);
		if ($rUserInfo['is_stalker']) {
			$rEncryptPlaylist = false;
		}

		$rDomainName = CoreUtilities::getDomainName();
		if (!$rDomainName) {
			exit();
		}

		if (!$rProxy) {
			$rRTMPRows = array();
			if ($rOutputKey == 'rtmp') {
				$db->query('SELECT t1.id,t2.server_id FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id WHERE t1.rtmp_output = 1');
				$rRTMPRows = $db->get_rows(true, 'id', false, 'server_id');
			}
		} else {
			if ($rOutputKey == 'rtmp') {
				$rOutputKey = 'ts';
			}
			$rRTMPRows = array();
		}

		if (empty($rOutputExt)) {
			$rOutputExt = 'ts';
		}

		$db->query('SELECT t1.*,t2.* FROM `output_devices` t1 LEFT JOIN `output_formats` t2 ON t2.access_output_id = t1.default_output WHERE t1.device_key = ? LIMIT 1', $rDeviceKey);
		if ($db->num_rows() <= 0) {
			return false;
		}
		$rDeviceInfo = $db->get_row();
		if (strlen($rUserInfo['access_token']) == 32) {
			$rFilename = str_replace('{USERNAME}', $rUserInfo['access_token'], $rDeviceInfo['device_filename']);
		} else {
			$rFilename = str_replace('{USERNAME}', $rUserInfo['username'], $rDeviceInfo['device_filename']);
		}

		if (0 < $rSettings['cache_playlists'] && !$rNoCache && file_exists(PLAYLIST_PATH . md5($rCacheName))) {
			header('Content-Description: File Transfer');
			header('Content-Type: audio/mpegurl');
			header('Expires: 0');
			header('Cache-Control: must-revalidate');
			header('Pragma: public');
			header('Content-Disposition: attachment; filename="' . $rFilename . '"');
			header('Content-Length: ' . filesize(PLAYLIST_PATH . md5($rCacheName)));
			readfile(PLAYLIST_PATH . md5($rCacheName));
			exit();
		}

		$rData = '';
		$rSeriesAllocation = $rSeriesEpisodes = $rSeriesInfo = array();
		$rUserInfo['episode_ids'] = array();
		if (count($rUserInfo['series_ids']) > 0) {
			if ($rCached) {
				foreach ($rUserInfo['series_ids'] as $rSeriesID) {
					$rSeriesInfo[$rSeriesID] = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_' . intval($rSeriesID)));
					$rSeriesData = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'episodes_' . intval($rSeriesID)));
					foreach ($rSeriesData as $rSeasonID => $rEpisodes) {
						foreach ($rEpisodes as $rEpisode) {
							$rSeriesEpisodes[$rEpisode['stream_id']] = array($rSeasonID, $rEpisode['episode_num']);
							$rSeriesAllocation[$rEpisode['stream_id']] = $rSeriesID;
							$rUserInfo['episode_ids'][] = $rEpisode['stream_id'];
						}
					}
				}
			} else {
				$db->query('SELECT * FROM `streams_series` WHERE `id` IN (' . implode(',', $rUserInfo['series_ids']) . ')');
				$rSeriesInfo = $db->get_rows(true, 'id');
				if (count($rUserInfo['series_ids']) > 0) {
					$db->query('SELECT stream_id, series_id, season_num, episode_num FROM `streams_episodes` WHERE series_id IN (' . implode(',', $rUserInfo['series_ids']) . ') ORDER BY FIELD(series_id,' . implode(',', $rUserInfo['series_ids']) . '), season_num ASC, episode_num ASC');
					foreach ($db->get_rows(true, 'series_id', false) as $rSeriesID => $rEpisodes) {
						foreach ($rEpisodes as $rEpisode) {
							$rSeriesEpisodes[$rEpisode['stream_id']] = array($rEpisode['season_num'], $rEpisode['episode_num']);
							$rSeriesAllocation[$rEpisode['stream_id']] = $rSeriesID;
							$rUserInfo['episode_ids'][] = $rEpisode['stream_id'];
						}
					}
				}
			}
		}

		if (count($rUserInfo['episode_ids']) > 0) {
			$rUserInfo['channel_ids'] = array_merge($rUserInfo['channel_ids'], $rUserInfo['episode_ids']);
		}

		$rChannelIDs = array();
		$rAdded = false;
		if ($rTypeKey) {
			foreach ($rTypeKey as $rType) {
				switch ($rType) {
					case 'live':
					case 'created_live':
						if (!$rAdded) {
							$rChannelIDs = array_merge($rChannelIDs, $rUserInfo['live_ids']);
							$rAdded = true;
						}
						break;
					case 'movie':
						$rChannelIDs = array_merge($rChannelIDs, $rUserInfo['vod_ids']);
						break;
					case 'radio_streams':
						$rChannelIDs = array_merge($rChannelIDs, $rUserInfo['radio_ids']);
						break;
					case 'series':
						$rChannelIDs = array_merge($rChannelIDs, $rUserInfo['episode_ids']);
						break;
				}
			}
		} else {
			$rChannelIDs = $rUserInfo['channel_ids'];
		}

		if (in_array($rSettings['channel_number_type'], array('bouquet_new', 'manual'))) {
			$rChannelIDs = CoreUtilities::sortChannels($rChannelIDs);
		}

		unset($rUserInfo['live_ids'], $rUserInfo['vod_ids'], $rUserInfo['radio_ids'], $rUserInfo['episode_ids'], $rUserInfo['channel_ids']);

		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		if (strlen($rUserInfo['access_token']) == 32) {
			header('Content-Disposition: attachment; filename="' . str_replace('{USERNAME}', $rUserInfo['access_token'], $rDeviceInfo['device_filename']) . '"');
		} else {
			header('Content-Disposition: attachment; filename="' . str_replace('{USERNAME}', $rUserInfo['username'], $rDeviceInfo['device_filename']) . '"');
		}

		$rOutputFile = null;
		if ($rSettings['cache_playlists'] == 1) {
			$rOutputPath = PLAYLIST_PATH . md5($rCacheName) . '.write';
			$rOutputFile = fopen($rOutputPath, 'w');
		}

		if ($rDeviceKey == 'starlivev5') {
			$rOutput = array();
			$rOutput['iptvstreams_list'] = array('@version' => 1, 'group' => array('name' => 'IPTV', 'channel' => array()));
			foreach (array_chunk($rChannelIDs, 1000) as $rBlockIDs) {
				if ($rSettings['playlist_from_mysql'] || !$rCached) {
					$rOrder = 'FIELD(`t1`.`id`,' . implode(',', $rBlockIDs) . ')';
					$db->query('SELECT t1.id,t1.channel_id,t1.year,t1.movie_properties,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t2.type_output,t2.type_key,t1.target_container,t2.live FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type WHERE `t1`.`id` IN (' . implode(',', array_map('intval', $rBlockIDs)) . ') ORDER BY ' . $rOrder . ';');
					$rRows = $db->get_rows();
				} else {
					$rRows = array();
					foreach ($rBlockIDs as $rID) {
						$rRows[] = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rID)))['info'];
					}
				}
				foreach ($rRows as $rChannelInfo) {
					if (!$rTypeKey || in_array($rChannelInfo['type_output'], $rTypeKey)) {
						if (!$rChannelInfo['target_container']) {
							$rChannelInfo['target_container'] = 'mp4';
						}
						$rProperties = (!is_array($rChannelInfo['movie_properties']) ? json_decode($rChannelInfo['movie_properties'], true) : $rChannelInfo['movie_properties']);
						if ($rChannelInfo['type_key'] == 'series') {
							$rSeriesID = $rSeriesAllocation[$rChannelInfo['id']];
							$rChannelInfo['live'] = 0;
							$rChannelInfo['stream_display_name'] = $rSeriesInfo[$rSeriesID]['title'] . ' S' . sprintf('%02d', $rSeriesEpisodes[$rChannelInfo['id']][0]) . 'E' . sprintf('%02d', $rSeriesEpisodes[$rChannelInfo['id']][1]);
							$rChannelInfo['movie_properties'] = array('movie_image' => (!empty($rProperties['movie_image']) ? $rProperties['movie_image'] : $rSeriesInfo['cover']));
							$rChannelInfo['type_output'] = 'series';
							$rChannelInfo['category_id'] = $rSeriesInfo[$rSeriesID]['category_id'];
						} else {
							$rChannelInfo['stream_display_name'] = CoreUtilities::formatTitle($rChannelInfo['stream_display_name'], $rChannelInfo['year']);
						}
						if (strlen($rUserInfo['access_token']) == 32) {
							$rURL = $rDomainName . $rChannelInfo['type_output'] . '/' . $rUserInfo['access_token'] . '/';
							if ($rChannelInfo['live'] == 0) {
								$rURL .= $rChannelInfo['id'] . '.' . $rChannelInfo['target_container'];
							} else {
								$rURL .= ($rSettings['cloudflare'] && $rOutputExt == 'ts') ? $rChannelInfo['id'] : ($rChannelInfo['id'] . '.' . $rOutputExt);
							}
						} else {
							if ($rEncryptPlaylist) {
								$rEncData = $rChannelInfo['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/';
								if ($rChannelInfo['live'] == 0) {
									$rEncData .= $rChannelInfo['id'] . '/' . $rChannelInfo['target_container'];
								} else {
									$rEncData .= ($rSettings['cloudflare'] && $rOutputExt == 'ts') ? $rChannelInfo['id'] : ($rChannelInfo['id'] . '/' . $rOutputExt);
								}
								$rToken = CoreUtilities::encryptData($rEncData, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
								$rURL = $rDomainName . 'play/' . $rToken;
								if ($rChannelInfo['live'] == 0) {
									$rURL .= '#.' . $rChannelInfo['target_container'];
								}
							} else {
								$rURL = $rDomainName . $rChannelInfo['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/';
								if ($rChannelInfo['live'] == 0) {
									$rURL .= $rChannelInfo['id'] . '.' . $rChannelInfo['target_container'];
								} else {
									$rURL .= ($rSettings['cloudflare'] && $rOutputExt == 'ts') ? $rChannelInfo['id'] : ($rChannelInfo['id'] . '.' . $rOutputExt);
								}
							}
						}
						$rIcon = ($rChannelInfo['live'] == 0 ? (!empty($rProperties['movie_image']) ? $rProperties['movie_image'] : null) : $rChannelInfo['stream_icon']);
						$rOutput['iptvstreams_list']['group']['channel'][] = array('name' => $rChannelInfo['stream_display_name'], 'icon' => CoreUtilities::validateImage($rIcon), 'stream_url' => $rURL, 'stream_type' => 0);
					}
				}
			}
			$rData = json_encode((object) $rOutput);
			if ($rOutputFile) {
				fwrite($rOutputFile, $rData);
			}
			echo $rData;
		} else {
			if (!empty($rDeviceInfo['device_header'])) {
				$epgUrl = $rDomainName . 'epg/' . $rUserInfo['username'] . '/' . $rUserInfo['password'];
				$isM3UFormat = (strpos($rDeviceInfo['device_header'], '#EXTM3U') !== false);
				if ($isM3UFormat && strpos($rDeviceInfo['device_header'], 'x-tvg-url') === false) {
					$rDeviceInfo['device_header'] = str_replace('#EXTM3U', '#EXTM3U x-tvg-url="' . $epgUrl . '"', $rDeviceInfo['device_header']);
				}
				$rAppend = ($isM3UFormat ? "\n" . '#EXT-X-SESSION-DATA:DATA-ID="com.xc_vm.' . str_replace('.', '_', XC_VM_VERSION) . '"' : '');
				$rData = str_replace(array('&lt;', '&gt;'), array('<', '>'), str_replace(array('{BOUQUET_NAME}', '{USERNAME}', '{PASSWORD}', '{SERVER_URL}', '{OUTPUT_KEY}'), array($rSettings['server_name'], $rUserInfo['username'], $rUserInfo['password'], $rDomainName, $rOutputKey), $rDeviceInfo['device_header'] . $rAppend)) . "\n";
				if ($rOutputFile) {
					fwrite($rOutputFile, $rData);
				}
				echo $rData;
			}

			if (!empty($rDeviceInfo['device_conf'])) {
				if (preg_match('/\{URL\#(.*?)\}/', $rDeviceInfo['device_conf'], $rMatches)) {
					$rCharts = str_split($rMatches[1]);
					$rPattern = $rMatches[0];
				} else {
					$rCharts = array();
					$rPattern = '{URL}';
				}

				foreach (array_chunk($rChannelIDs, 1000) as $rBlockIDs) {
					if ($rSettings['playlist_from_mysql'] || !$rCached) {
						$rOrder = 'FIELD(`t1`.`id`,' . implode(',', $rBlockIDs) . ')';
						$db->query('SELECT t1.id,t1.channel_id,t1.year,t1.movie_properties,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t2.type_output,t2.type_key,t1.target_container,t2.live,t1.tv_archive_duration,t1.tv_archive_server_id FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type WHERE `t1`.`id` IN (' . implode(',', array_map('intval', $rBlockIDs)) . ') ORDER BY ' . $rOrder . ';');
						$rRows = $db->get_rows();
					} else {
						$rRows = array();
						foreach ($rBlockIDs as $rID) {
							$rRows[] = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rID)))['info'];
						}
					}

					foreach ($rRows as $rChannel) {
						if ($rTypeKey && !in_array($rChannel['type_output'], $rTypeKey)) {
							continue;
						}
						if (!$rChannel['target_container']) {
							$rChannel['target_container'] = 'mp4';
						}
						$rConfig = $rDeviceInfo['device_conf'];
						if ($rDeviceInfo['device_key'] == 'm3u_plus') {
							if (!$rChannel['live']) {
								$rConfig = str_replace('tvg-id="{CHANNEL_ID}" ', '', $rConfig);
							}
							if (!$rEncryptPlaylist) {
								$rConfig = str_replace('xc_vm-id="{XC_VM_ID}" ', '', $rConfig);
							}
							if (0 < $rChannel['tv_archive_server_id'] && 0 < $rChannel['tv_archive_duration']) {
								$rConfig = str_replace('#EXTINF:-1 ', '#EXTINF:-1 timeshift="' . intval($rChannel['tv_archive_duration']) . '" ', $rConfig);
							}
						}

						$rProperties = (!is_array($rChannel['movie_properties']) ? json_decode($rChannel['movie_properties'], true) : $rChannel['movie_properties']);
						if ($rChannel['type_key'] == 'series') {
							$rSeriesID = $rSeriesAllocation[$rChannel['id']];
							$rChannel['live'] = 0;
							$rChannel['stream_display_name'] = $rSeriesInfo[$rSeriesID]['title'] . ' S' . sprintf('%02d', $rSeriesEpisodes[$rChannel['id']][0]) . 'E' . sprintf('%02d', $rSeriesEpisodes[$rChannel['id']][1]);
							$rChannel['movie_properties'] = array('movie_image' => (!empty($rProperties['movie_image']) ? $rProperties['movie_image'] : $rSeriesInfo['cover']));
							$rChannel['type_output'] = 'series';
							$rChannel['category_id'] = $rSeriesInfo[$rSeriesID]['category_id'];
						} else {
							$rChannel['stream_display_name'] = CoreUtilities::formatTitle($rChannel['stream_display_name'], $rChannel['year']);
						}

						if ($rChannel['live'] == 0) {
							if (strlen($rUserInfo['access_token']) == 32) {
								$rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['access_token'] . '/' . $rChannel['id'] . '.' . $rChannel['target_container'];
							} else if ($rEncryptPlaylist) {
								$rEncData = $rChannel['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'] . '/' . $rChannel['target_container'];
								$rToken = CoreUtilities::encryptData($rEncData, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
								$rURL = $rDomainName . 'play/' . $rToken . '#.' . $rChannel['target_container'];
							} else {
								$rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'] . '.' . $rChannel['target_container'];
							}
							if (!empty($rProperties['movie_image'])) {
								$rIcon = $rProperties['movie_image'];
							}
						} else {
							if ($rOutputKey != 'rtmp' || !array_key_exists($rChannel['id'], $rRTMPRows)) {
								if (strlen($rUserInfo['access_token']) == 32) {
									if ($rSettings['cloudflare'] && $rOutputExt == 'ts') {
										$rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['access_token'] . '/' . $rChannel['id'];
									} else {
										$rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['access_token'] . '/' . $rChannel['id'] . '.' . $rOutputExt;
									}
								} else if ($rEncryptPlaylist) {
									$rEncData = $rChannel['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'];
									$rToken = CoreUtilities::encryptData($rEncData, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
									if ($rSettings['cloudflare'] && $rOutputExt == 'ts') {
										$rURL = $rDomainName . 'play/' . $rToken;
									} else {
										$rURL = $rDomainName . 'play/' . $rToken . '/' . $rOutputExt;
									}
								} else {
									if ($rSettings['cloudflare'] && $rOutputExt == 'ts') {
										$rURL = $rDomainName . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'];
									} else {
										$rURL = $rDomainName . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'] . '.' . $rOutputExt;
									}
								}
							} else {
								$rAvailableServers = array_values(array_keys($rRTMPRows[$rChannel['id']]));
								if (in_array($rUserInfo['force_server_id'], $rAvailableServers)) {
									$rServerID = $rUserInfo['force_server_id'];
								} else {
									$rServerID = ($rSettings['rtmp_random'] == 1 ? $rAvailableServers[array_rand($rAvailableServers, 1)] : $rAvailableServers[0]);
								}
								if (strlen($rUserInfo['access_token']) == 32) {
									$rURL = $rServers[$rServerID]['rtmp_server'] . $rChannel['id'] . '?token=' . $rUserInfo['access_token'];
								} else if ($rEncryptPlaylist) {
									$rEncData = $rUserInfo['username'] . '/' . $rUserInfo['password'];
									$rToken = CoreUtilities::encryptData($rEncData, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
									$rURL = $rServers[$rServerID]['rtmp_server'] . $rChannel['id'] . '?token=' . $rToken;
								} else {
									$rURL = $rServers[$rServerID]['rtmp_server'] . $rChannel['id'] . '?username=' . $rUserInfo['username'] . '&password=' . $rUserInfo['password'];
								}
							}
							$rIcon = $rChannel['stream_icon'];
						}

						$rESRID = ($rChannel['live'] == 1 ? 1 : 4097);
						$rSID = (!empty($rChannel['custom_sid']) ? $rChannel['custom_sid'] : ':0:1:0:0:0:0:0:0:0:');
						$rCategoryIDs = json_decode($rChannel['category_id'], true);
						if (empty($rCategoryIDs)) {
							$rCategoryIDs = [0];
						}
						foreach ($rCategoryIDs as $rCategoryID) {
							if (isset($rCategories[$rCategoryID])) {
								$rData = str_replace(array('&lt;', '&gt;'), array('<', '>'), str_replace(array($rPattern, '{ESR_ID}', '{SID}', '{CHANNEL_NAME}', '{CHANNEL_ID}', '{XC_VM_ID}', '{CATEGORY}', '{CHANNEL_ICON}'), array(str_replace($rCharts, array_map('urlencode', $rCharts), $rURL), $rESRID, $rSID, $rChannel['stream_display_name'], $rChannel['channel_id'], $rChannel['id'], $rCategories[$rCategoryID]['category_name'], CoreUtilities::validateImage($rIcon)), $rConfig)) . "\r\n";
							} else {
								$rData = str_replace(array('&lt;', '&gt;'), array('<', '>'), str_replace(array($rPattern, '{ESR_ID}', '{SID}', '{CHANNEL_NAME}', '{CHANNEL_ID}', '{XC_VM_ID}', '{CHANNEL_ICON}'), array(str_replace($rCharts, array_map('urlencode', $rCharts), $rURL), $rESRID, $rSID, $rChannel['stream_display_name'], $rChannel['channel_id'], $rChannel['id'], $rIcon), $rConfig)) . "\r\n";
								$rData = str_replace(' group-title="{CATEGORY}"', '', $rData);
							}
							if ($rOutputFile) {
								fwrite($rOutputFile, $rData);
							}
							echo $rData;
							if (stripos($rDeviceInfo['device_conf'], '{CATEGORY}') === false) {
								break;
							}
						}
					}
				}

				$rData = trim(str_replace(array('&lt;', '&gt;'), array('<', '>'), $rDeviceInfo['device_footer']));
				if ($rOutputFile) {
					fwrite($rOutputFile, $rData);
				}
				echo $rData;
			}
		}

		if ($rOutputFile) {
			fclose($rOutputFile);
			rename(PLAYLIST_PATH . md5($rCacheName) . '.write', PLAYLIST_PATH . md5($rCacheName));
		}
		exit();
	}
}
