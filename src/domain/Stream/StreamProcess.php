<?php

class StreamProcess {
	public static function deleteCache($rSources) {
		if (!empty($rSources)) {
			foreach ($rSources as $rSource) {
				if (!file_exists(CACHE_TMP_PATH . md5($rSource))) {
				} else {
					unlink(CACHE_TMP_PATH . md5($rSource));
				}
			}
		} else {
			return null;
		}
	}

	public static function queueChannel($db, $rStreamID, $rServerID = null) {
		if ($rServerID) {
		} else {
			$rServerID = SERVER_ID;
		}
		$db->query('SELECT `id` FROM `queue` WHERE `stream_id` = ? AND `server_id` = ?;', $rStreamID, $rServerID);
		if ($db->num_rows() != 0) {
		} else {
			$db->query("INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES('channel', ?, ?, ?);", $rStreamID, $rServerID, time());
		}
	}

	public static function createChannel($rStreamID) {
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'created.php ' . intval($rStreamID) . ' >/dev/null 2>/dev/null &');
		return true;
	}

	public static function startMonitor($rStreamID, $rRestart = 0) {
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'monitor.php ' . intval($rStreamID) . ' ' . intval($rRestart) . ' >/dev/null 2>/dev/null &');
		return true;
	}

	public static function startProxy($rStreamID) {
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'proxy.php ' . intval($rStreamID) . ' >/dev/null 2>/dev/null &');
		return true;
	}

	public static function startThumbnail($rStreamID) {
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'thumbnail.php ' . intval($rStreamID) . ' >/dev/null 2>/dev/null &');
		return true;
	}

	public static function updateStream($db, $rCached, $rMainID, $rStreamID, $rForce = false) {
		if ($rCached) {
			$db->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', $rMainID, json_encode(array('type' => 'update_stream', 'id' => $rStreamID)));
			if ($db->get_row()['count'] != 0) {
			} else {
				$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', $rMainID, time(), json_encode(array('type' => 'update_stream', 'id' => $rStreamID)));
			}
			return true;
		}
		return false;
	}

	public static function updateStreams($db, $rCached, $rMainID, $rStreamIDs) {
		if ($rCached) {
			$db->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', $rMainID, json_encode(array('type' => 'update_streams', 'id' => $rStreamIDs)));
			if ($db->get_row()['count'] != 0) {
			} else {
				$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', $rMainID, time(), json_encode(array('type' => 'update_streams', 'id' => $rStreamIDs)));
			}
			return true;
		}
		return false;
	}

	public static function createChannelItem($db, $rSettings, $rServers, $rFFMPEGCPU, $rFFMPEGGPU, $rStreamID, $rSource) {
		$rStream = array();
		$rLoopback = false;
		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t1.type = 3 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);
		if ($db->num_rows() > 0) {
			$rStream['stream_info'] = $db->get_row();
			$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
			if ($db->num_rows() > 0) {
				$rStream['server_info'] = $db->get_row();
				$rMD5 = md5($rSource);
				if (substr($rSource, 0, 2) == 's:') {
					$rSplit = explode(':', $rSource, 3);
					$rServerID = intval($rSplit[1]);
					$rSourcePath = $rSplit[2];
					if ($rServerID != SERVER_ID) {
						if (is_array($rServers) && isset($rServers[$rServerID])) {
							$rSourcePath = $rServers[$rServerID]['api_url'] . '&action=getFile&filename=' . urlencode($rSplit[2]);
						} else {
							$rSourcePath = $rSplit[2];
						}
					}
				} else {
					$rServerID = SERVER_ID;
					$rSourcePath = $rSource;
				}

				if ($rServerID == SERVER_ID && intval($rStream['stream_info']['movie_symlink']) == 1) {
					$rExtension = pathinfo($rSource)['extension'];
					if (strlen($rExtension) == 0) {
						$rExtension = 'mp4';
					}
					$rCommand = 'ln -sfn ' . escapeshellarg($rSourcePath) . ' "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.' . escapeshellcmd($rExtension) . '" >/dev/null 2>/dev/null & echo $! > "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid"';
				} else {
					$rStream['stream_info']['transcode_attributes'] = json_decode($rStream['stream_info']['profile_options'], true);
					if (!is_array($rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes'] = array();
					}

					$rLogoOptions = '';
					if (isset($rStream['stream_info']['transcode_attributes'][16]) && !$rLoopback) {
						$rAttr = $rStream['stream_info']['transcode_attributes'];
						$rLogoPath = $rAttr[16]['val'];
						$rPos = (isset($rAttr[16]['pos']) && $rAttr[16]['pos'] !== '10:10') ? $rAttr[16]['pos'] : '10:main_h-overlay_h-10';

						$rChain = array();
						$rBase = '[0:v]';
						$rVideoFilters = array();
						if (isset($rAttr[17])) {
							$rVideoFilters[] = 'yadif';
						}
						if (isset($rAttr[9]['val']) && strlen($rAttr[9]['val']) > 0) {
							$rVideoFilters[] = 'scale=' . $rAttr[9]['val'];
						}

						if (!empty($rVideoFilters)) {
							$rChain[] = $rBase . implode(',', $rVideoFilters) . '[bg]';
							$rBase = '[bg]';
						}

						$rChain[] = '[1:v]scale=250:-1[logo]';
						$rChain[] = $rBase . '[logo]overlay=' . $rPos;

						$rLogoOptions = '-i ' . escapeshellarg($rLogoPath) . ' -filter_complex "' . implode('; ', $rChain) . '"';
						unset($rStream['stream_info']['transcode_attributes'][16]);
					}

					$rGPUOptions = (isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rStream['stream_info']['transcode_attributes']['gpu']['cmd'] : '');
					$rInputCodec = '';
					if (!empty($rGPUOptions)) {
						$rFFProbeOutput = CoreUtilities::probeStream($rSourcePath);
						if (in_array($rFFProbeOutput['codecs']['video']['codec_name'], array('h264', 'hevc', 'mjpeg', 'mpeg1', 'mpeg2', 'mpeg4', 'vc1', 'vp8', 'vp9'))) {
							$rInputCodec = '-c:v ' . $rFFProbeOutput['codecs']['video']['codec_name'] . '_cuvid';
						}
					}

					$rCommand = ((isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rFFMPEGGPU : $rFFMPEGCPU)) . ' -y -nostdin -hide_banner -loglevel ' . (($rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -err_detect ignore_err {GPU} -fflags +genpts -async 1 -i {STREAM_SOURCE} {LOGO} ';

					if (!array_key_exists('-acodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-acodec'] = 'copy';
					}
					if (!array_key_exists('-vcodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-vcodec'] = 'copy';
					}
					if (isset($rStream['stream_info']['transcode_attributes']['gpu'])) {
						$rCommand .= '-gpu ' . intval($rStream['stream_info']['transcode_attributes']['gpu']['device']) . ' ';
					}
					$rCommand .= implode(' ', CoreUtilities::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
					$rCommand .= '-strict -2 -mpegts_flags +initial_discontinuity -f mpegts "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.ts"';
					$rCommand .= ' >/dev/null 2>"' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.errors" & echo $! > "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid"';
					$rCommand = str_replace(array('{GPU}', '{INPUT_CODEC}', '{LOGO}', '{STREAM_SOURCE}'), array($rGPUOptions, $rInputCodec, $rLogoOptions, escapeshellarg($rSourcePath)), $rCommand);
				}

				shell_exec($rCommand);
				return intval(file_get_contents(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid'));
			}
			return false;
		}
		return false;
	}

	public static function stopStream($db, $rStreamID, $rStop = false) {
		if (file_exists(STREAMS_PATH . $rStreamID . '_.monitor')) {
			$rMonitor = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.monitor'));
		} else {
			$db->query('SELECT `monitor_pid` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` = ? LIMIT 1;', SERVER_ID, $rStreamID);
			$rMonitor = intval($db->get_row()['monitor_pid']);
		}

		if (0 < $rMonitor && CoreUtilities::checkPID($rMonitor, array('XC_VM[' . $rStreamID . ']', 'XC_VMProxy[' . $rStreamID . ']')) && is_numeric($rMonitor)) {
			posix_kill($rMonitor, 9);
		}

		if (file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
			$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
		} else {
			$db->query('SELECT `pid` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` = ? LIMIT 1;', SERVER_ID, $rStreamID);
			$rPID = intval($db->get_row()['pid']);
		}

		if (0 < $rPID && CoreUtilities::checkPID($rPID, array($rStreamID . '_.m3u8', $rStreamID . '_%d.ts', 'LLOD[' . $rStreamID . ']', 'XC_VMProxy[' . $rStreamID . ']', 'Loopback[' . $rStreamID . ']')) && is_numeric($rPID)) {
			posix_kill($rPID, 9);
		}

		if (file_exists(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID))) {
			unlink(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID));
		}

		CoreUtilities::streamLog($rStreamID, SERVER_ID, 'STREAM_STOP');
		shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*');

		if ($rStop) {
			shell_exec('rm -f ' . DELAY_PATH . intval($rStreamID) . '_*');
			$db->query('UPDATE `streams_servers` SET `bitrate` = NULL,`current_source` = NULL,`to_analyze` = 0,`pid` = NULL,`stream_started` = NULL,`stream_info` = NULL,`audio_codec` = NULL,`video_codec` = NULL,`resolution` = NULL,`compatible` = 0,`stream_status` = 0,`monitor_pid` = NULL WHERE `stream_id` = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
			CoreUtilities::updateStream($rStreamID);
		}
	}

	public static function stopMovie($db, $rStreamID, $rForce = false) {
		shell_exec("kill -9 `ps -ef | grep '/" . intval($rStreamID) . ".' | grep -v grep | awk '{print \$2}'`;" );
		if ($rForce) {
			exec('rm ' . MAIN_HOME . 'content/vod/' . intval($rStreamID) . '.*');
		} else {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', SERVER_ID, time(), json_encode(array('type' => 'delete_vod', 'id' => $rStreamID)));
		}
		$db->query('UPDATE `streams_servers` SET `bitrate` = NULL,`current_source` = NULL,`to_analyze` = 0,`pid` = NULL,`stream_started` = NULL,`stream_info` = NULL,`audio_codec` = NULL,`video_codec` = NULL,`resolution` = NULL,`compatible` = 0,`stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
		CoreUtilities::updateStream($rStreamID);
	}

	public static function queueMovie($db, $rStreamID, $rServerID = null) {
		if ($rServerID) {
		} else {
			$rServerID = SERVER_ID;
		}
		$db->query('DELETE FROM `queue` WHERE `stream_id` = ? AND `server_id` = ?;', $rStreamID, $rServerID);
		$db->query("INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES('movie', ?, ?, ?);", $rStreamID, $rServerID, time());
	}

	public static function queueMovies($db, $rStreamIDs, $rServerID = null) {
		if ($rServerID) {
		} else {
			$rServerID = SERVER_ID;
		}
		if (0 >= count($rStreamIDs)) {
		} else {
			$db->query('DELETE FROM `queue` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ') AND `server_id` = ?;', $rServerID);
			$rQuery = '';
			foreach ($rStreamIDs as $rStreamID) {
				if (0 >= $rStreamID) {
				} else {
					$rQuery .= "('movie', " . intval($rStreamID) . ', ' . intval($rServerID) . ', ' . time() . '),';
				}
			}
			if (empty($rQuery)) {
			} else {
				$rQuery = rtrim($rQuery, ',');
				$db->query('INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES ' . $rQuery . ';');
			}
		}
	}

	public static function refreshMovies($db, $rIDs, $rType = 1) {
		if (0 >= count($rIDs)) {
		} else {
			$db->query('DELETE FROM `watch_refresh` WHERE `type` = ? AND `stream_id` IN (' . implode(',', array_map('intval', $rIDs)) . ');', $rType);
			$rQuery = '';
			foreach ($rIDs as $rID) {
				if (0 >= $rID) {
				} else {
					$rQuery .= '(' . intval($rType) . ', ' . intval($rID) . ', 0),';
				}
			}
			if (empty($rQuery)) {
			} else {
				$rQuery = rtrim($rQuery, ',');
				$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES ' . $rQuery . ';');
			}
		}
	}

	public static function startMovie($db, $rSettings, $rServers, $rFFMPEGCPU, $rFFMPEGGPU, $rStreamID) {
		$rStream = array();
		$rLoopback = false;
		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = 0 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);
		if ($db->num_rows() > 0) {
			$rStream['stream_info'] = $db->get_row();
			$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
			if ($db->num_rows() > 0) {
				$rStream['server_info'] = $db->get_row();
				$db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
				$rStream['stream_arguments'] = $db->get_rows();

				list($rStreamSource) = json_decode($rStream['stream_info']['stream_source'], true);
				if (substr($rStreamSource, 0, 2) == 's:') {
					$rMovieSource = explode(':', $rStreamSource, 3);
					$rMovieServerID = $rMovieSource[1];
					if ($rMovieServerID != SERVER_ID) {
						$rMoviePath = $rServers[$rMovieServerID]['api_url'] . '&action=getFile&filename=' . urlencode($rMovieSource[2]);
					} else {
						$rMoviePath = $rMovieSource[2];
					}
					$rProtocol = null;
				} else {
					if (substr($rStreamSource, 0, 1) == '/') {
						$rMovieServerID = SERVER_ID;
						$rMoviePath = $rStreamSource;
						$rProtocol = null;
					} else {
						$rProtocol = substr($rStreamSource, 0, strpos($rStreamSource, '://'));
						$rMoviePath = str_replace(' ', '%20', $rStreamSource);
						$rFetchOptions = implode(' ', CoreUtilities::getArguments($rStream['stream_arguments'], $rProtocol, 'fetch'));
					}
				}

				if ((isset($rMovieServerID) && $rMovieServerID == SERVER_ID || file_exists($rMoviePath)) && $rStream['stream_info']['movie_symlink'] == 1) {
					$rFFMPEG = 'ln -sfn ' . escapeshellarg($rMoviePath) . ' ' . VOD_PATH . intval($rStreamID) . '.' . escapeshellcmd(pathinfo($rMoviePath)['extension']) . ' >/dev/null 2>/dev/null & echo $! > ' . VOD_PATH . intval($rStreamID) . '_.pid';
				} else {
					$rSubtitles = json_decode($rStream['stream_info']['movie_subtitles'], true);
					$rSubtitlesImport = '';
					$rSubtitlesMetadata = '';
					if (!empty($rSubtitles) && !empty($rSubtitles['files']) && is_array($rSubtitles['files'])) {
						for ($i = 0; $i < count($rSubtitles['files']); $i++) {
							$rSubtitleFile = escapeshellarg($rSubtitles['files'][$i]);
							$rInputCharset = escapeshellarg($rSubtitles['charset'][$i]);
							if ($rSubtitles['location'] == SERVER_ID) {
								$rSubtitlesImport .= '-sub_charenc ' . $rInputCharset . ' -i ' . $rSubtitleFile . ' ';
							} else {
								$rSubtitlesImport .= '-sub_charenc ' . $rInputCharset . ' -i "' . $rServers[$rSubtitles['location']]['api_url'] . '&action=getFile&filename=' . urlencode($rSubtitleFile) . '" ';
							}
							for ($i = 0; $i < count($rSubtitles['files']); $i++) {
								$rSubtitlesMetadata .= '-map ' . ($i + 1) . ' -metadata:s:s:' . $i . ' title=' . escapeshellcmd($rSubtitles['names'][$i]) . ' -metadata:s:s:' . $i . ' language=' . escapeshellcmd($rSubtitles['names'][$i]) . ' ';
							}
						}
					}

					$rReadNative = ($rStream['stream_info']['read_native'] == 1 ? '-re' : '');
					if ($rStream['stream_info']['enable_transcode'] == 1) {
						if ($rStream['stream_info']['transcode_profile_id'] == -1) {
							$rDecoded = json_decode($rStream['stream_info']['transcode_attributes'], true);
							$rStream['stream_info']['transcode_attributes'] = array_merge(CoreUtilities::getArguments($rStream['stream_arguments'], $rProtocol, 'transcode'), (is_array($rDecoded) ? $rDecoded : array()));
						} else {
							$rDecoded = json_decode($rStream['stream_info']['profile_options'], true);
							$rStream['stream_info']['transcode_attributes'] = (is_array($rDecoded) ? $rDecoded : array());
						}
					} else {
						$rStream['stream_info']['transcode_attributes'] = array();
					}

					$rLogoOptions = '';
					if (isset($rStream['stream_info']['transcode_attributes'][16]) && !$rLoopback) {
						$rAttr = $rStream['stream_info']['transcode_attributes'];
						$rLogoPath = $rAttr[16]['val'];
						$rPos = (isset($rAttr[16]['pos']) && $rAttr[16]['pos'] !== '10:10') ? $rAttr[16]['pos'] : '10:main_h-overlay_h-10';

						$rChain = array();
						$rBase = '[0:v]';
						$rVideoFilters = array();
						if (isset($rAttr[17])) {
							$rVideoFilters[] = 'yadif';
						}
						if (isset($rAttr[9]['val']) && strlen($rAttr[9]['val']) > 0) {
							$rVideoFilters[] = 'scale=' . $rAttr[9]['val'];
						}

						if (!empty($rVideoFilters)) {
							$rChain[] = $rBase . implode(',', $rVideoFilters) . '[bg]';
							$rBase = '[bg]';
						}

						$rChain[] = '[1:v]scale=250:-1[logo]';
						$rChain[] = $rBase . '[logo]overlay=' . $rPos;

						$rLogoOptions = '-i ' . escapeshellarg($rLogoPath) . ' -filter_complex "' . implode('; ', $rChain) . '"';
						unset($rStream['stream_info']['transcode_attributes'][16]);
					}
					$rGPUOptions = (isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rStream['stream_info']['transcode_attributes']['gpu']['cmd'] : '');
					$rInputCodec = '';
					if (!empty($rGPUOptions)) {
						$rFFProbeOutput = CoreUtilities::probeStream($rMoviePath);
						if (in_array($rFFProbeOutput['codecs']['video']['codec_name'], array('h264', 'hevc', 'mjpeg', 'mpeg1', 'mpeg2', 'mpeg4', 'vc1', 'vp8', 'vp9'))) {
							$rInputCodec = '-c:v ' . $rFFProbeOutput['codecs']['video']['codec_name'] . '_cuvid';
						}
					}
					$rFFMPEG = ((isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rFFMPEGGPU : $rFFMPEGCPU)) . ' -y -nostdin -hide_banner -loglevel ' . (($rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -err_detect ignore_err {GPU} {FETCH_OPTIONS} -fflags +genpts -async 1 {READ_NATIVE} -i {STREAM_SOURCE} {LOGO} ' . $rSubtitlesImport;
					$rMap = '-map 0 -copy_unknown ';
					if (!empty($rStream['stream_info']['custom_map'])) {
						$rMap = escapeshellcmd($rStream['stream_info']['custom_map']) . ' -copy_unknown ';
					} else {
						if ($rStream['stream_info']['remove_subtitles'] == 1) {
							$rMap = '-map 0:a -map 0:v';
						}
					}
					if (!array_key_exists('-acodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-acodec'] = 'copy';
					}
					if (!array_key_exists('-vcodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-vcodec'] = 'copy';
					}
					if ($rStream['stream_info']['target_container'] == 'mp4') {
						$rStream['stream_info']['transcode_attributes']['-scodec'] = 'mov_text';
					} elseif ($rStream['stream_info']['target_container'] == 'mkv') {
						$rStream['stream_info']['transcode_attributes']['-scodec'] = 'srt';
					} else {
						$rStream['stream_info']['transcode_attributes']['-scodec'] = 'copy';
					}
					$rOutputs = array();
					$rOutputs[$rStream['stream_info']['target_container']] = '-movflags +faststart -dn ' . $rMap . ' -ignore_unknown ' . $rSubtitlesMetadata . ' ' . VOD_PATH . intval($rStreamID) . '.' . escapeshellcmd($rStream['stream_info']['target_container']);
					foreach ($rOutputs as $rOutputCommand) {
						$rFFMPEG .= implode(' ', CoreUtilities::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
						$rFFMPEG .= $rOutputCommand;
					}
					$rFFMPEG .= ' >/dev/null 2>' . VOD_PATH . intval($rStreamID) . '.errors & echo $! > ' . VOD_PATH . intval($rStreamID) . '_.pid';
					$rFFMPEG = str_replace(array('{GPU}', '{INPUT_CODEC}', '{LOGO}', '{FETCH_OPTIONS}', '{STREAM_SOURCE}', '{READ_NATIVE}'), array($rGPUOptions, $rInputCodec, $rLogoOptions, (empty($rFetchOptions) ? '' : $rFetchOptions), escapeshellarg($rMoviePath), (empty($rStream['stream_info']['custom_ffmpeg']) ? $rReadNative : '')), $rFFMPEG);
				}

				shell_exec($rFFMPEG);
				file_put_contents(VOD_PATH . $rStreamID . '_.ffmpeg', $rFFMPEG);
				$rPID = intval(file_get_contents(VOD_PATH . $rStreamID . '_.pid'));
				$db->query('UPDATE `streams_servers` SET `to_analyze` = 1,`stream_started` = ?,`stream_status` = 0,`pid` = ? WHERE `stream_id` = ? AND `server_id` = ?', time(), $rPID, $rStreamID, SERVER_ID);
				CoreUtilities::updateStream($rStreamID);
				return $rPID;
			}
			return false;
		}
		return false;
	}

	public static function startLoopback($db, $rSettings, $rServers, $rStreamID) {
		shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*.ts');
		if (!file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
		} else {
			unlink(STREAMS_PATH . $rStreamID . '_.pid');
		}
		$rStream = array();
		$db->query('SELECT * FROM `streams` WHERE direct_source = 0 AND id = ?', $rStreamID);
		if ($db->num_rows() > 0) {
			$rStream['stream_info'] = $db->get_row();
			$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
			if ($db->num_rows() > 0) {
				$rStream['server_info'] = $db->get_row();
				if ($rStream['server_info']['parent_id'] != 0) {
					shell_exec(PHP_BIN . ' ' . CLI_PATH . 'loopback.php ' . intval($rStreamID) . ' ' . intval($rStream['server_info']['parent_id']) . ' >/dev/null 2>/dev/null & echo $! > ' . STREAMS_PATH . intval($rStreamID) . '_.pid');
					$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
					$rLoopURL = (!is_null($rServers[SERVER_ID]['private_url_ip']) && !is_null($rServers[$rStream['server_info']['parent_id']]['private_url_ip']) ? $rServers[$rStream['server_info']['parent_id']]['private_url_ip'] : $rServers[$rStream['server_info']['parent_id']]['public_url_ip']);
					$rCurrentSource = $rLoopURL . 'admin/live?stream=' . intval($rStreamID) . '&password=' . urlencode($rSettings['live_streaming_pass']) . '&extension=ts';
					$rKey = openssl_random_pseudo_bytes(16);
					file_put_contents(STREAMS_PATH . $rStreamID . '_.key', $rKey);
					$rIVSize = openssl_cipher_iv_length('AES-128-CBC');
					$rIV = openssl_random_pseudo_bytes($rIVSize);
					file_put_contents(STREAMS_PATH . $rStreamID . '_.iv', $rIV);
					$db->query('UPDATE `streams_servers` SET `delay_available_at` = ?,`to_analyze` = 0,`stream_started` = ?,`stream_info` = ?,`stream_status` = 2,`pid` = ?,`progress_info` = ?,`current_source` = ? WHERE `stream_id` = ? AND `server_id` = ?', null, time(), null, $rPID, json_encode(array()), $rCurrentSource, $rStreamID, SERVER_ID);
					CoreUtilities::updateStream($rStreamID);
					return array('main_pid' => $rPID, 'stream_source' => $rLoopURL . 'admin/live?stream=' . intval($rStreamID) . '&password=' . urlencode($rSettings['live_streaming_pass']) . '&extension=ts', 'delay_enabled' => false, 'parent_id' => 0, 'delay_start_at' => null, 'playlist' => STREAMS_PATH . $rStreamID . '_.m3u8', 'transcode' => false, 'offset' => 0);
				}
				return 0;
			}
			return false;
		}
		return false;
	}

	public static function startLLOD($db, $rStreamID, $rStreamInfo, $rStreamArguments, $rForceSource = null) {
		shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*.ts');
		if (file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
			unlink(STREAMS_PATH . $rStreamID . '_.pid');
		}
		$rSources = ($rForceSource ? array($rForceSource) : json_decode($rStreamInfo['stream_source'], true));
		$rArgumentMap = array();
		foreach ($rStreamArguments as $rStreamArgument) {
			$rArgumentMap[$rStreamArgument['argument_key']] = array('value' => $rStreamArgument['value'], 'argument_default_value' => $rStreamArgument['argument_default_value']);
		}
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'llod.php ' . intval($rStreamID) . ' "' . base64_encode(json_encode($rSources)) . '" "' . base64_encode(json_encode($rArgumentMap)) . '" >/dev/null 2>/dev/null & echo $! > ' . STREAMS_PATH . intval($rStreamID) . '_.pid');
		$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
		$rKey = openssl_random_pseudo_bytes(16);
		file_put_contents(STREAMS_PATH . $rStreamID . '_.key', $rKey);
		$rIVSize = openssl_cipher_iv_length('AES-128-CBC');
		$rIV = openssl_random_pseudo_bytes($rIVSize);
		file_put_contents(STREAMS_PATH . $rStreamID . '_.iv', $rIV);
		$db->query('UPDATE `streams_servers` SET `delay_available_at` = ?,`to_analyze` = 0,`stream_started` = ?,`stream_info` = ?,`stream_status` = 2,`pid` = ?,`progress_info` = ?,`current_source` = ? WHERE `stream_id` = ? AND `server_id` = ?', null, time(), null, $rPID, json_encode(array()), $rSources[0], $rStreamID, SERVER_ID);
		CoreUtilities::updateStream($rStreamID);
		return array('main_pid' => $rPID, 'stream_source' => $rSources[0], 'delay_enabled' => false, 'parent_id' => 0, 'delay_start_at' => null, 'playlist' => STREAMS_PATH . $rStreamID . '_.m3u8', 'transcode' => false, 'offset' => 0);
	}

	public static function startStream($db, $rSettings, $rServers, $rSegmentSettings, $rFFMPEGCPU, $rFFMPEGGPU, $rFFPROBE, $rStreamID, $rFromCache = false, $rForceSource = null, $rLLOD = false, $rStartPos = 0) {
		if (file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
			unlink(STREAMS_PATH . $rStreamID . '_.pid');
		}

		$rStream = array();
		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = 1 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);

		if ($db->num_rows() > 0) {
			$rStream['stream_info'] = $db->get_row();
			$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);

			if ($db->num_rows() > 0) {
				$rStream['server_info'] = $db->get_row();
				$db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
				$rStream['stream_arguments'] = $db->get_rows();

				if ($rStream['server_info']['on_demand'] == 1) {
					$rProbesize = intval($rStream['stream_info']['probesize_ondemand']);
					$rAnalyseDuration = '10000000';
				} else {
					$rAnalyseDuration = abs(intval($rSettings['stream_max_analyze']));
					$rProbesize = abs(intval($rSettings['probesize']));
				}

				$rTimeout = intval($rAnalyseDuration / 1000000) + $rSettings['probe_extra_wait'];
				$rFFProbee = 'timeout ' . $rTimeout . ' ' . $rFFPROBE . ' {FETCH_OPTIONS} -probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' {CONCAT} -i {STREAM_SOURCE} -v quiet -print_format json -show_streams -show_format';
				$rFetchOptions = array();
				$rLoopback = false;
				$rOffset = 0;

				if (!$rStream['server_info']['parent_id']) {
					if ($rStream['stream_info']['type_key'] == 'created_live') {
						$rSources = array(CREATED_PATH . $rStreamID . '_.list');

						if ($rStartPos > 0) {
							$rCCOutput = array();
							$rCCDuration = array();
							$rCCInfo = json_decode($rStream['server_info']['cc_info'], true);

							foreach ($rCCInfo as $rItem) {
								$rCCDuration[$rItem['path']] = intval(explode('.', $rItem['seconds'])[0]);
							}
							$rTimer = 0;
							$rValid = true;

							foreach (explode("\n", file_get_contents(CREATED_PATH . $rStreamID . '_.list')) as $rItem) {
								list($rPath) = explode("'", explode("file '", $rItem)[1]);

								if ($rPath) {
									if ($rCCDuration[$rPath]) {
										$rDuration = $rCCDuration[$rPath];

										if ($rTimer <= $rStartPos && $rStartPos < $rTimer + $rDuration) {
											$rOffset = $rTimer;
											$rCCOutput[] = $rPath;
										} else {
											if ($rStartPos < $rTimer + $rDuration) {
												$rCCOutput[] = $rPath;
											}
										}

										$rTimer += $rDuration;
									} else {
										$rValid = false;
									}
								}
							}

							if ($rValid) {
								$rSources = array(CREATED_PATH . $rStreamID . '_.tlist');
								$rTList = '';

								foreach ($rCCOutput as $rItem) {
									$rTList .= "file '" . $rItem . "'" . "\n";
								}
								file_put_contents(CREATED_PATH . $rStreamID . '_.tlist', $rTList);
							}
						}
					} else {
						$rSources = json_decode($rStream['stream_info']['stream_source'], true);
					}

					if (count($rSources) > 0) {
						if (!empty($rForceSource)) {
							$rSources = array($rForceSource);
						} else {
							if ($rSettings['priority_backup'] != 1) {
								if (!empty($rStream['server_info']['current_source'])) {
									$k = array_search($rStream['server_info']['current_source'], $rSources);

									if ($k !== false) {
										$i = 0;

										while ($i <= $k) {
											$rTemp = $rSources[$i];
											unset($rSources[$i]);
											array_push($rSources, $rTemp);
											$i++;
										}
										$rSources = array_values($rSources);
									}
								}
							}
						}
					}
				} else {
					$rLoopback = true;

					if ($rStream['server_info']['on_demand']) {
						$rLLOD = true;
					}

					$rLoopURL = (!is_null($rServers[SERVER_ID]['private_url_ip']) && !is_null($rServers[$rStream['server_info']['parent_id']]['private_url_ip']) ? $rServers[$rStream['server_info']['parent_id']]['private_url_ip'] : $rServers[$rStream['server_info']['parent_id']]['public_url_ip']);
					$rSources = array($rLoopURL . 'admin/live?stream=' . intval($rStreamID) . '&password=' . urlencode($rSettings['live_streaming_pass']) . '&extension=ts');
				}

				if ($rStream['stream_info']['type_key'] == 'created_live' && file_exists(CREATED_PATH . $rStreamID . '_.info')) {
					$db->query('UPDATE `streams_servers` SET `cc_info` = ? WHERE `server_id` = ? AND `stream_id` = ?;', file_get_contents(CREATED_PATH . $rStreamID . '_.info'), SERVER_ID, $rStreamID);
				}

				if (!$rFromCache) {
					self::deleteCache($rSources);
				}

				foreach ($rSources as $rSource) {
					$rProcessed = false;
					$rRealSource = $rSource;
					$rStreamSource = CoreUtilities::parseStreamURL($rSource);
					echo 'Checking source: ' . $rSource . "\n";
					$rURLInfo = parse_url($rStreamSource);
					$rIsXC_VM = ($rLoopback ? true : CoreUtilities::detectXC_VM($rStreamSource));

					if ($rIsXC_VM && !$rLoopback && $rSettings['send_xc_vm_header']) {
						foreach (array_keys($rStream['stream_arguments']) as $rID) {
							if ($rStream['stream_arguments'][$rID]['argument_key'] == 'headers') {
								$rStream['stream_arguments'][$rID]['value'] .= "\r\n" . 'X-XC_VM-Detect:1';
								$rProcessed = true;
							}
						}

						if (!$rProcessed) {
							$rStream['stream_arguments'][] = array('value' => 'X-XC_VM-Detect:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'");
						}
					}

					$rProbeArguments = $rStream['stream_arguments'];

					if ($rIsXC_VM && $rStream['server_info']['on_demand'] == 1 && $rSettings['request_prebuffer'] == 1) {
						foreach (array_keys($rStream['stream_arguments']) as $rID) {
							if ($rStream['stream_arguments'][$rID]['argument_key'] == 'headers') {
								$rStream['stream_arguments'][$rID]['value'] .= "\r\n" . 'X-XC_VM-Prebuffer:1';
								$rProcessed = true;
							}
						}

						if (!$rProcessed) {
							$rStream['stream_arguments'][] = array('value' => 'X-XC_VM-Prebuffer:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'");
						}
					}

					foreach (array_keys($rProbeArguments) as $rID) {
						if ($rProbeArguments[$rID]['argument_key'] == 'headers') {
							$rProbeArguments[$rID]['value'] .= "\r\n" . 'X-XC_VM-Prebuffer:1';
							$rProcessed = true;
						}
					}

					if (!$rProcessed) {
						$rProbeArguments[] = array('value' => 'X-XC_VM-Prebuffer:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'");
					}

					$rProtocol = strtolower(substr($rStreamSource, 0, strpos($rStreamSource, '://')));
					$rProbeOptions = implode(' ', CoreUtilities::getArguments($rProbeArguments, $rProtocol, 'fetch'));
					$rFetchOptions = implode(' ', CoreUtilities::getArguments($rStream['stream_arguments'], $rProtocol, 'fetch'));

					$rSkipFFProbe = false;
					foreach ($rStream['stream_arguments'] as $rArg) {
						if ($rArg['argument_key'] == 'skip_ffprobe' && $rArg['value'] == 1) {
							$rSkipFFProbe = true;
							break;
						}
					}

					if ($rSkipFFProbe) {
						$rFFProbeOutput = array(
							'codecs' => array(
								'video' => array('codec_name' => 'h264', 'codec_type' => 'video', 'height' => 1080),
								'audio' => array('codec_name' => 'aac', 'codec_type' => 'audio')
							),
							'container' => 'mpegts'
						);
						error_log('[XC_VM] Stream ' . $rStreamID . ': FFProbe skipped');
						echo 'Got stream information via skip_ffprobe (assumed h264/aac)' . "\n";

						if (empty($rSource)) {
							$rSource = is_array($rSources) && count($rSources) > 0 ? $rSources[0] : $rStreamSource;
						}
						break;
					}

					if ($rFromCache && file_exists(CACHE_TMP_PATH . md5($rSource)) && time() - filemtime(CACHE_TMP_PATH . md5($rSource)) <= 300) {
						$rFFProbeOutput = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . md5($rStreamSource)));

						if ($rFFProbeOutput && (isset($rFFProbeOutput['streams']) || isset($rFFProbeOutput['codecs']))) {
							echo 'Got stream information via cache' . "\n";

							break;
						}
					} else {
						if ($rFromCache && file_exists(CACHE_TMP_PATH . md5($rSource))) {
							$rFromCache = false;
						}
					}

					if (!($rStream['server_info']['on_demand'] && $rLLOD)) {
						if ($rIsXC_VM && $rSettings['api_probe']) {
							$rProbeURL = $rURLInfo['scheme'] . '://' . $rURLInfo['host'] . ':' . $rURLInfo['port'] . '/probe/' . base64_encode($rURLInfo['path']);
							$rFFProbeOutput = json_decode(CoreUtilities::getURL($rProbeURL), true);

							if ($rFFProbeOutput && isset($rFFProbeOutput['codecs'])) {
								echo 'Got stream information via API' . "\n";

								break;
							}
						}

						$rProbeCmd = str_replace(array('{FETCH_OPTIONS}', '{CONCAT}', '{STREAM_SOURCE}'), array($rProbeOptions, ($rStream['stream_info']['type_key'] == 'created_live' && !$rStream['server_info']['parent_id'] ? '-safe 0 -f concat' : ''), escapeshellarg($rStreamSource)), $rFFProbee);
						$rFFProbeOutput = json_decode(shell_exec($rProbeCmd), true);

						if ($rFFProbeOutput && isset($rFFProbeOutput['streams'])) {
							echo 'Got stream information via ffprobe' . "\n";

							break;
						}
					}
				}
				if (!($rStream['server_info']['on_demand'] && $rLLOD)) {
					if (!isset($rFFProbeOutput['codecs'])) {
						$rFFProbeOutput = CoreUtilities::parseFFProbe($rFFProbeOutput);
					}

					if (empty($rFFProbeOutput)) {
						$db->query("UPDATE `streams_servers` SET `progress_info` = '',`to_analyze` = 0,`pid` = -1,`stream_status` = 1 WHERE `server_id` = ? AND `stream_id` = ?", SERVER_ID, $rStreamID);

						return 0;
					}

					if (!$rFromCache) {
						file_put_contents(CACHE_TMP_PATH . md5($rSource), igbinary_serialize($rFFProbeOutput));
					}
				}

				$externalPushJson = $rStream['stream_info']['external_push'] ?? '[]';
				$rExternalPush = json_decode($externalPushJson, true);
				$rProgressURL = 'http://127.0.0.1:' . intval($rServers[SERVER_ID]['http_broadcast_port']) . '/progress?stream_id=' . intval($rStreamID);

				if (empty($rStream['stream_info']['custom_ffmpeg'])) {
					if ($rLoopback) {
						$rOptions = '{FETCH_OPTIONS}';
					} else {
						$rOptions = '{GPU} {FETCH_OPTIONS}';
					}

					if ($rStream['stream_info']['stream_all'] == 1) {
						$rMap = '-map 0 -copy_unknown ';
					} else {
						if (!empty($rStream['stream_info']['custom_map'])) {
							$rMap = escapeshellcmd($rStream['stream_info']['custom_map']) . ' -copy_unknown ';
						} else {
							if ($rStream['stream_info']['type_key'] == 'radio_streams') {
								$rMap = '-map 0:a? ';
							} else {
								$rMap = '';
							}
						}
					}

					if (($rStream['stream_info']['gen_timestamps'] == 1 || empty($rProtocol)) && $rStream['stream_info']['type_key'] != 'created_live') {
						$rGenPTS = '-fflags +genpts -async 1';
					} else {
						if (is_array($rFFProbeOutput) && isset($rFFProbeOutput['codecs']['audio']['codec_name']) && in_array($rFFProbeOutput['codecs']['audio']['codec_name'], array('ac3', 'eac3')) && $rSettings['dts_legacy_ffmpeg']) {
							$rFFMPEGCPU = FFMPEG_BIN_40;
							$rFFPROBE = FFPROBE_BIN_40;
						}

						$rNoFix = ($rFFMPEGCPU == FFMPEG_BIN_40 ? '-nofix_dts' : '');
						$rGenPTS = $rNoFix . ' -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0';
					}

					$container = (isset($rFFProbeOutput) && is_array($rFFProbeOutput)) ? ($rFFProbeOutput['container'] ?? null) : null;
					if (empty($rStream['server_info']['parent_id']) && (($rStream['stream_info']['read_native'] == 1) || ($container && stristr($container, 'hls') && $rSettings['read_native_hls']) || empty($rProtocol) || ($container && stristr($container, 'mp4')) || ($container && stristr($container, 'matroska')))) {
						$rReadNative = '-re';
					} else {
						$rReadNative = '';
					}

					if (!$rStream['server_info']['parent_id'] && $rStream['stream_info']['enable_transcode'] == 1 && $rStream['stream_info']['type_key'] != 'created_live') {
						if ($rStream['stream_info']['transcode_profile_id'] == -1) {
							$rStream['stream_info']['transcode_attributes'] = array_merge(CoreUtilities::getArguments($rStream['stream_arguments'], $rProtocol, 'transcode'), json_decode($rStream['stream_info']['transcode_attributes'], true));
						} else {
							$rStream['stream_info']['transcode_attributes'] = json_decode($rStream['stream_info']['profile_options'], true);
						}
					} else {
						$rStream['stream_info']['transcode_attributes'] = array();
					}

					$rFFMPEG = ((isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rFFMPEGGPU : $rFFMPEGCPU)) . ' -y -nostdin -hide_banner -loglevel ' . (($rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -err_detect ignore_err ' . $rOptions . ' {GEN_PTS} {READ_NATIVE} -probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' -progress "' . $rProgressURL . '" {CONCAT} -i {STREAM_SOURCE} {LOGO} ';

					if (!array_key_exists('-acodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-acodec'] = 'copy';
					}

					if (!array_key_exists('-vcodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-vcodec'] = 'copy';
					}

					if (!array_key_exists('-scodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-sn'] = '';
					}
				} else {
					$rStream['stream_info']['transcode_attributes'] = array();
					$rFFMPEG = ((stripos($rStream['stream_info']['custom_ffmpeg'], 'nvenc') !== false ? $rFFMPEGGPU : $rFFMPEGCPU)) . ' -y -nostdin -hide_banner -loglevel ' . (($rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -progress "' . $rProgressURL . '" ' . $rStream['stream_info']['custom_ffmpeg'];
				}

				$rLLODOptions = ($rLLOD && !$rLoopback ? '-fflags nobuffer -flags low_delay -strict experimental' : '');
				$rOutputs = array();

				if ($rLoopback) {
					$rOptions = '{MAP}';
					$rFLVOptions = '{MAP}';
					$rMap = '-map 0 -copy_unknown ';
				} else {
					$rOptions = '{MAP} {LLOD}';
					$rFLVOptions = '{MAP} {AAC_FILTER}';
				}

				$rKeyFrames = ($rSettings['ignore_keyframes'] ? '+split_by_time' : '');
				$rOutputs['mpegts'][] = $rOptions . ' -individual_header_trailer 0 -f hls -hls_time ' . intval($rSegmentSettings['seg_time']) . ' -hls_list_size ' . intval($rSegmentSettings['seg_list_size']) . ' -hls_delete_threshold ' . intval($rSegmentSettings['seg_delete_threshold']) . ' -hls_flags delete_segments+discont_start+omit_endlist' . $rKeyFrames . ' -hls_segment_type mpegts -hls_segment_filename "' . STREAMS_PATH . intval($rStreamID) . '_%d.ts" "' . STREAMS_PATH . intval($rStreamID) . '_.m3u8" ';

				if ($rStream['stream_info']['rtmp_output'] == 1) {
					$rOutputs['flv'][] = $rFLVOptions . ' -f flv -flvflags no_duration_filesize rtmp://127.0.0.1:' . intval($rServers[$rStream['server_info']['server_id']]['rtmp_port']) . '/live/' . intval($rStreamID) . '?password=' . urlencode($rSettings['live_streaming_pass']) . ' ';
				}

				if (!empty($rExternalPush[SERVER_ID])) {
					foreach ($rExternalPush[SERVER_ID] as $rPushURL) {
						$rOutputs['flv'][] = $rFLVOptions . ' -f flv -flvflags no_duration_filesize ' . escapeshellarg($rPushURL) . ' ';
					}
				}

				$rLogoOptions = '';
				if (isset($rStream['stream_info']['transcode_attributes'][16]) && !$rLoopback) {
					$rAttr = $rStream['stream_info']['transcode_attributes'];
					$rLogoPath = $rAttr[16]['val'];
					$rPos = (isset($rAttr[16]['pos']) && $rAttr[16]['pos'] !== '10:10') ? $rAttr[16]['pos'] : '10:main_h-overlay_h-10';

					$rChain = array();
					$rBase = '[0:v]';
					$rVideoFilters = array();
					if (isset($rAttr[17])) {
						$rVideoFilters[] = 'yadif';
					}
					if (isset($rAttr[9]['val']) && strlen($rAttr[9]['val']) > 0) {
						$rVideoFilters[] = 'scale=' . $rAttr[9]['val'];
					}

					if (!empty($rVideoFilters)) {
						$rChain[] = $rBase . implode(',', $rVideoFilters) . '[bg]';
						$rBase = '[bg]';
					}

					$rChain[] = '[1:v]scale=250:-1[logo]';
					$rChain[] = $rBase . '[logo]overlay=' . $rPos;

					$rLogoOptions = '-i ' . escapeshellarg($rLogoPath) . ' -filter_complex "' . implode('; ', $rChain) . '"';
					unset($rStream['stream_info']['transcode_attributes'][16]);
				}

				$rGPUOptions = (isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rStream['stream_info']['transcode_attributes']['gpu']['cmd'] : '');
				$rInputCodec = '';

				$supportedCodecs = ['h264', 'hevc', 'mjpeg', 'mpeg1', 'mpeg2', 'mpeg4', 'vc1', 'vp8', 'vp9'];
				$videoCodec = null;
				if (isset($rFFProbeOutput) && is_array($rFFProbeOutput)) {
					$videoCodec = $rFFProbeOutput['codecs']['video']['codec_name'] ?? null;
				}

				if (!empty($rGPUOptions) && in_array($videoCodec, $supportedCodecs)) {
					$rInputCodec = '-c:v ' . $rFFProbeOutput['codecs']['video']['codec_name'] . '_cuvid';
				}

				if (0 >= $rStream['stream_info']['delay_minutes'] || $rStream['server_info']['parent_id']) {
					foreach ($rOutputs as $rOutputCommands) {
						foreach ($rOutputCommands as $rOutputCommand) {
							if (isset($rStream['stream_info']['transcode_attributes']['gpu'])) {
								$rFFMPEG .= '-gpu ' . intval($rStream['stream_info']['transcode_attributes']['gpu']['device']) . ' ';
							}

							$rFFMPEG .= implode(' ', CoreUtilities::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
							$rFFMPEG .= $rOutputCommand;
						}
					}
				} else {
					$rSegmentStart = 0;
					$m3u8File = DELAY_PATH . $rStreamID . '_.m3u8';
					$oldM3u8File = DELAY_PATH . intval($rStreamID) . '_.m3u8_old';

					if (file_exists($m3u8File)) {
						$rFile = file($m3u8File, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

						if (!is_array($rFile) || count($rFile) < 2) {
							return;
						}

						$lastLine = $rFile[count($rFile) - 1];
						$prevLine = $rFile[count($rFile) - 2];

						if (stristr($lastLine, $rStreamID . '_')) {
							if (preg_match('/_(.*?)\.ts/', $lastLine, $rMatches)) {
								$rSegmentStart = intval($rMatches[1]) + 1;
							}
						} else {
							if (preg_match('/_(.*?)\.ts/', $prevLine, $rMatches)) {
								$rSegmentStart = intval($rMatches[1]) + 1;
							}
						}

						if (file_exists($oldM3u8File)) {
							file_put_contents($oldM3u8File, file_get_contents($oldM3u8File) . file_get_contents($m3u8File));
							shell_exec("sed -i '/EXTINF\\|.ts/!d' " . escapeshellarg($oldM3u8File));
						} else {
							copy($m3u8File, $oldM3u8File);
						}
					}

					$rFFMPEG .= implode(' ', CoreUtilities::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
					$rFFMPEG .= '{MAP} -individual_header_trailer 0 -f hls -hls_time ' . intval($rSegmentSettings['seg_time']) . ' -hls_list_size ' . intval($rStream['stream_info']['delay_minutes']) * 6 . ' -hls_delete_threshold 4 -start_number ' . $rSegmentStart . ' -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "' . DELAY_PATH . intval($rStreamID) . '_%d.ts" "' . DELAY_PATH . intval($rStreamID) . '_.m3u8" ';

					$rSleepTime = $rStream['stream_info']['delay_minutes'] * 60;

					if ($rSegmentStart > 0) {
						$rSleepTime -= ($rSegmentStart - 1) * 10;

						if ($rSleepTime > 0) {
						} else {
							$rSleepTime = 0;
						}
					}
				}

				$rFFMPEG .= ' >/dev/null 2>>' . STREAMS_PATH . intval($rStreamID) . '.errors & echo $! > ' . STREAMS_PATH . intval($rStreamID) . '_.pid';

				$ffprobeContainer = (isset($rFFProbeOutput['container']) && is_string($rFFProbeOutput['container'])) ? $rFFProbeOutput['container'] : '';

				$audioCodec = (isset($rFFProbeOutput['codecs']['audio']['codec_name']) && is_array($rFFProbeOutput['codecs']['audio'])) ? $rFFProbeOutput['codecs']['audio']['codec_name'] : '';

				$rFFMPEG = str_replace(
					['{FETCH_OPTIONS}', '{GEN_PTS}', '{STREAM_SOURCE}', '{MAP}', '{READ_NATIVE}', '{CONCAT}', '{AAC_FILTER}', '{GPU}', '{INPUT_CODEC}', '{LOGO}', '{LLOD}'],
					[
						empty($rStream['stream_info']['custom_ffmpeg']) ? $rFetchOptions : '',
						empty($rStream['stream_info']['custom_ffmpeg']) ? $rGenPTS : '',
						escapeshellarg($rStreamSource),
						empty($rStream['stream_info']['custom_ffmpeg']) ? $rMap : '',
						empty($rStream['stream_info']['custom_ffmpeg']) ? $rReadNative : '',
						($rStream['stream_info']['type_key'] == 'created_live' && empty($rStream['server_info']['parent_id']) ? '-safe 0 -f concat' : ''),
						(!stristr($ffprobeContainer, 'flv') && $audioCodec === 'aac' && ($rStream['stream_info']['transcode_attributes']['-acodec'] ?? '') === 'copy' ? '-bsf:a aac_adtstoasc' : ''),
						$rGPUOptions,
						$rInputCodec,
						$rLogoOptions,
						$rLLODOptions
					],
					$rFFMPEG
				);

				shell_exec($rFFMPEG);
				file_put_contents(STREAMS_PATH . $rStreamID . '_.ffmpeg', $rFFMPEG);
				$rKey = openssl_random_pseudo_bytes(16);
				file_put_contents(STREAMS_PATH . $rStreamID . '_.key', $rKey);
				$rIVSize = openssl_cipher_iv_length('AES-128-CBC');
				$rIV = openssl_random_pseudo_bytes($rIVSize);
				file_put_contents(STREAMS_PATH . $rStreamID . '_.iv', $rIV);
				$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));

				if ($rStream['stream_info']['tv_archive_server_id'] == SERVER_ID) {
					shell_exec(PHP_BIN . ' ' . CLI_PATH . 'archive.php ' . intval($rStreamID) . ' >/dev/null 2>/dev/null & echo $!');
				}

				if ($rStream['stream_info']['vframes_server_id'] == SERVER_ID) {
					self::startThumbnail($rStreamID);
				}

				$rDelayEnabled = 0 < $rStream['stream_info']['delay_minutes'] && !$rStream['server_info']['parent_id'];
				$rDelayStartAt = ($rDelayEnabled ? time() + $rSleepTime : 0);

				if ($rStream['stream_info']['enable_transcode']) {
					$rFFProbeOutput = array();
				}

				$rCompatible = 0;
				$rAudioCodec = $rVideoCodec = $rResolution = null;

				if (isset($rFFProbeOutput) && is_array($rFFProbeOutput) && isset($rFFProbeOutput['codecs']) && is_array($rFFProbeOutput['codecs'])) {
					$rCompatible = intval(CoreUtilities::checkCompatibility($rFFProbeOutput));
					$rAudioCodec = ($rFFProbeOutput['codecs']['audio']['codec_name'] ?: null);
					$rVideoCodec = ($rFFProbeOutput['codecs']['video']['codec_name'] ?: null);
					$rResolution = ($rFFProbeOutput['codecs']['video']['height'] ?: null);

					if ($rResolution) {
						$rResolution = CoreUtilities::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
					}
				}

				$rFFProbeOutputSafe = isset($rFFProbeOutput) && is_array($rFFProbeOutput) ? $rFFProbeOutput : [];
				$db->query('UPDATE `streams_servers` SET `delay_available_at` = ?,`to_analyze` = 0,`stream_started` = ?,`stream_info` = ?,`audio_codec` = ?, `video_codec` = ?, `resolution` = ?,`compatible` = ?,`stream_status` = 2,`pid` = ?,`progress_info` = ?,`current_source` = ? WHERE `stream_id` = ? AND `server_id` = ?', $rDelayStartAt, time(), json_encode($rFFProbeOutputSafe), $rAudioCodec, $rVideoCodec, $rResolution, $rCompatible, $rPID, json_encode(array()), $rSource, $rStreamID, SERVER_ID);
				CoreUtilities::updateStream($rStreamID);
				$rPlaylist = (!$rDelayEnabled ? STREAMS_PATH . $rStreamID . '_.m3u8' : DELAY_PATH . $rStreamID . '_.m3u8');

				return array('main_pid' => $rPID, 'stream_source' => $rRealSource, 'delay_enabled' => $rDelayEnabled, 'parent_id' => $rStream['server_info']['parent_id'], 'delay_start_at' => $rDelayStartAt, 'playlist' => $rPlaylist, 'transcode' => $rStream['stream_info']['enable_transcode'], 'offset' => $rOffset);
			} else {
				return false;
			}
		} else {
			return false;
		}
	}
}
