<?php

/**
 * Мониторинг одного стрима (из cli/monitor.php).
 *
 * Запуск: php console.php monitor <stream_id> [restart]
 *
 * ВНИМАНИЕ: Этот файл содержит goto/label flow-control — следы обфускации.
 * Планируется рефакторинг в Phase 14 (→ domain/Stream/StreamMonitor.php).
 * На данном этапе логика перенесена as-is.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MonitorCommand implements CommandInterface {

	public function getName(): string {
		return 'monitor';
	}

	public function getDescription(): string {
		return 'Monitor stream by ID (start/restart/track)';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		if (empty($rArgs[0])) {
			return 0;
		}

		$rStreamID = intval($rArgs[0]);
		$rRestart = !empty($rArgs[1]);

		global $db;

		$this->checkRunning($rStreamID);
		set_time_limit(0);
		cli_set_process_title('XC_VM[' . $rStreamID . ']');

		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.server_id = ? WHERE t1.id = ?', SERVER_ID, $rStreamID);
		if ($db->num_rows() <= 0) {
			StreamProcess::stopStream($rStreamID);
			return 0;
		}

		$rStreamInfo = $db->get_row();
		$db->query('UPDATE `streams_servers` SET `monitor_pid` = ? WHERE `server_stream_id` = ?', getmypid(), $rStreamInfo['server_stream_id']);

		if (SettingsManager::getAll()['enable_cache']) {
			StreamProcess::updateStream($rStreamID);
		}

		$rPID = (file_exists(STREAMS_PATH . $rStreamID . '_.pid') ? intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid')) : $rStreamInfo['pid']);
		$rAutoRestart = json_decode($rStreamInfo['auto_restart'], true);
		$rPlaylist = STREAMS_PATH . $rStreamID . '_.m3u8';
		$rDelayPID = $rStreamInfo['delay_pid'];
		$rParentID = $rStreamInfo['parent_id'];
		$rStreamProbe = false;
		$rSources = array();
		$rSegmentTime = intval(SettingsManager::getAll()['seg_time']);
		$rPrioritySwitch = false;
		$rMaxFails = 0;

		if ($rParentID == 0) {
			$rSources = json_decode($rStreamInfo['stream_source'], true);
		}

		$rCurrentSource = ($rParentID <= 0) ? $rStreamInfo['current_source'] : 'Loopback: #' . $rParentID;
		$rLastSegment = $rForceSource = null;

		$db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
		$rStreamArguments = $db->get_rows();

		if (!(0 < $rStreamInfo['delay_minutes']) && ($rStreamInfo['parent_id'] == 0)) {
			$rDelay = false;
			$rFolder = STREAMS_PATH;
		} else {
			$rFolder = DELAY_PATH;
			$rPlaylist = DELAY_PATH . $rStreamID . '_.m3u8';
			$rDelay = true;
		}

		$rFirstRun = true;
		$rTotalCalls = 0;

		// Initial check if stream is running
		if (ProcessManager::isStreamRunning($rPID, $rStreamID)) {
			echo "Stream is running.\n";
			if ($rRestart) {
				$rTotalCalls = MONITOR_CALLS;
				if (is_numeric($rPID) && $rPID > 0) {
					shell_exec('kill -9 ' . intval($rPID));
				}
				shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*');
				file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor', getmypid());
				if ($rDelay && ProcessManager::isNamedProcessRunning($rDelayPID, 'XC_VMDelay', $rStreamID) && is_numeric($rDelayPID) && $rDelayPID > 0) {
					shell_exec('kill -9 ' . intval($rDelayPID));
				}
				usleep(50000);
				$rDelayPID = $rPID = 0;
			}
		} else {
			file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor', getmypid());
		}

		if (SettingsManager::getAll()['kill_rogue_ffmpeg']) {
			exec('ps aux | grep -v grep | grep \'/' . $rStreamID . '_.m3u8\' | awk \'{print $2}\'', $rRoguePIDs);
			foreach ($rRoguePIDs as $rRoguePID) {
				if (is_numeric($rRoguePID) && intval($rRoguePID) > 0 && intval($rRoguePID) != intval($rPID)) {
					shell_exec('kill -9 ' . $rRoguePID . ';');
				}
			}
		}

		// ── Main monitoring loop (goto-based control flow) ───
		// ПРИМЕЧАНИЕ: goto/label — следы обфускации. Рефакторинг запланирован в Phase 14.
		goto label235;
		label235:
		if (true) {
			if (!(0 < $rPID)) {
				goto label471;
			}
			$db->close_mysql();
			$rStartedTime = $rDurationChecked = $rAudioChecked = $rCheckedTime = $rBackupsChecked = time();
			$rMD5 = md5_file($rPlaylist);
			$D97a4f098a8d1bf8 = ProcessManager::isStreamRunning($rPID, $rStreamID) && file_exists($rPlaylist);
			$b4015d24aedaf0db = null;
			goto label592;
			label592: //while
			if ((ProcessManager::isStreamRunning($rPID, $rStreamID) && file_exists($rPlaylist))) {
				if (!(!empty($rAutoRestart['days']) && !empty($rAutoRestart['at']))) {
					goto label195;
				}
				list($rHour, $rMinutes) = explode(':', $rAutoRestart['at']);
				if (!(in_array(date('l'), $rAutoRestart['days']) && (date('H') == $rHour))) {
					goto label195;
				}
				if (!($rMinutes == date('i'))) {
					goto label195;
				}
				echo "Auto-restart\n";
				StreamProcess::streamLog($rStreamID, SERVER_ID, 'AUTO_RESTART', $rCurrentSource);
				$D97a4f098a8d1bf8 = false;
				goto label1186;
			}
			goto label1186;
			label195:
			if (($rStreamProbe || (!file_exists(STREAMS_PATH . $rStreamID . '_.dur') && (300 < (time() - $rDurationChecked))))) {
				echo "Probe Stream\n";
				$rSegment = StreamUtils::getPlaylistSegments($rPlaylist, 10)[0];
				if (!empty($rSegment)) {
					if (((300 < (time() - $rDurationChecked)) && ($rSegment == $rLastSegment))) {
						StreamProcess::streamLog($rStreamID, SERVER_ID, 'FFMPEG_ERROR', $rCurrentSource);
						goto label1186;
					}
					$rLastSegment = $rSegment;
					$E02429d2ee600884 = FFprobeRunner::probeStream($rFolder . $rSegment);
					if ((10 < intval($E02429d2ee600884['of_duration']))) {
						$E02429d2ee600884['of_duration'] = 10;
					}
					file_put_contents(STREAMS_PATH . $rStreamID . '_.dur', intval($E02429d2ee600884['of_duration']));
					if (($rSegmentTime < intval($E02429d2ee600884['of_duration']))) {
						$rSegmentTime = intval($E02429d2ee600884['of_duration']);
					}
					file_put_contents(STREAMS_PATH . $rStreamID . '_.stream_info', json_encode($E02429d2ee600884, JSON_UNESCAPED_UNICODE));
					$rStreamInfo['stream_info'] = json_encode($E02429d2ee600884, JSON_UNESCAPED_UNICODE);
				}
				$rStreamProbe = false;
				$rDurationChecked = time();
				if (!file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
					file_put_contents(STREAMS_PATH . $rStreamID . '_.pid', $rPID);
				}
				if (!file_exists(STREAMS_PATH . $rStreamID . '_.monitor')) {
					file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor', getmypid());
				}
			}
			if (!(($rStreamInfo['fps_restart'] == 1) && (SettingsManager::getAll()['fps_delay'] < (time() - $rStartedTime)) && file_exists(STREAMS_PATH . $rStreamID . '_.progress_check'))) {
				goto label298;
			}
			echo "Checking FPS...\n";
			$d75674a646265e7b = floatval(json_decode(file_get_contents(STREAMS_PATH . $rStreamID . '_.progress_check'), true)['fps']) ?: 0;
			if (!(0 < $d75674a646265e7b)) {
				goto label1847;
			}
			if (!$b4015d24aedaf0db) {
				goto label1087;
			}
			if (!($b4015d24aedaf0db && (($d75674a646265e7b * ($rStreamInfo['fps_threshold'] ?: 100)) < $b4015d24aedaf0db))) {
				goto label1847;
			}
			echo "FPS dropped below threshold! Break\n";
			StreamProcess::streamLog($rStreamID, SERVER_ID, 'FPS_DROP_THRESHOLD', $rCurrentSource);
			goto label1186;
			label884:
			$rArguments = implode(' ', StreamUtils::getArguments($rStreamArguments, $rProtocol, 'fetch'));
			if (($E02429d2ee600884 = FFprobeRunner::probeStream($rStreamSource, $rArguments))) {
				echo "Force new source\n";
				StreamProcess::streamLog($rStreamID, SERVER_ID, 'FORCE_SOURCE', $rSources[$rForceID]);
				$rForceSource = $rSources[$rForceID];
				unlink(SIGNALS_TMP_PATH . $rStreamID . '.force');
				$D97a4f098a8d1bf8 = false;
				goto label1186;
			}
			goto label1631;
			label1631:
			unlink(SIGNALS_TMP_PATH . $rStreamID . '.force');
			label496:
			if ((file_exists(SIGNALS_TMP_PATH . $rStreamID . '.force') && ($rParentID == 0))) {
				$rForceID = intval(file_get_contents(SIGNALS_TMP_PATH . $rStreamID . '.force'));
				$rStreamSource = StreamUtils::parseStreamURL($rSources[$rForceID]);
				if (($rSources[$rForceID] != $rCurrentSource)) {
					$rProtocol = strtolower(substr($rStreamSource, 0, strpos($rStreamSource, '://')));
					goto label884;
				}
				goto label1631;
			}
			if (($rDelay && ($rStreamInfo['delay_available_at'] <= time()) && !ProcessManager::isNamedProcessRunning($rDelayPID, 'XC_VMDelay', $rStreamID))) {
				echo "Start Delay\n";
				StreamProcess::streamLog($rStreamID, SERVER_ID, 'DELAY_START');
				$rDelayPID = intval(shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php delay ' . intval($rStreamID) . ' ' . intval($rStreamInfo['delay_minutes']) . ' >/dev/null 2>/dev/null & echo $!'));
			}
			sleep(1);
			goto label592;
		}
		goto label1880;
		label1:
		if (!$rStreamInfo['parent_id']) {
			goto label49;
		}
		$rForceSource = (!is_null(ServerRepository::getAll()[SERVER_ID]['private_url_ip']) && !is_null(ServerRepository::getAll()[$rStreamInfo['parent_id']]['private_url_ip']) ? ServerRepository::getAll()[$rStreamInfo['parent_id']]['private_url_ip'] : ServerRepository::getAll()[$rStreamInfo['parent_id']]['public_url_ip']) . 'admin/live?stream=' . intval($rStreamID) . '&password=' . urlencode(SettingsManager::getAll()['live_streaming_pass']) . '&extension=ts';
		label49:
		$rData = StreamProcess::startLLOD($rStreamID, $rStreamInfo, $rStreamInfo['parent_id'] ? array() : $rStreamArguments, $rForceSource);
		goto label644;
		label1512:
		if ($rForceSource) {
			$Ea84d0933a1ef2f0 = $rForceSource;
		} else {
			$Ea84d0933a1ef2f0 = json_decode($rStreamInfo['stream_source'], true)[0];
		}
		$rData = StreamProcess::startStream($rStreamID, false, $Ea84d0933a1ef2f0, true);
		label644:
		goto label1131;
		label1127:
		$rData = StreamProcess::startLoopback($rStreamID);
		label1131:
		if ((is_numeric($rData) && ($rData == 0))) {
			$E9d347a502b13abd = true;
			$rMaxFails++;
			if (((0 < SettingsManager::getAll()['stop_failures']) && ($rMaxFails >= SettingsManager::getAll()['stop_failures']))) {
				echo "Failure limit reached, exiting.\n";
				return 0;
			}
			echo 'Stream start failed (attempt ' . $rMaxFails . '). Sleeping ' . SettingsManager::getAll()['stream_fail_sleep'] . " seconds...\n";
			sleep(SettingsManager::getAll()['stream_fail_sleep']);
			goto label76;
		}
		if (!$rData) {
			return 0;
		}
		if ($E9d347a502b13abd) {
			goto label562;
		}
		$rPID = intval($rData['main_pid']);
		if ($rPID) {
			file_put_contents(STREAMS_PATH . $rStreamID . '_.pid', $rPID);
		}
		$rPlaylist = $rData['playlist'];
		$rDelay = $rData['delay_enabled'];
		$rStreamInfo['delay_available_at'] = $rData['delay_start_at'];
		$rParentID = $rData['parent_id'];
		if (0 >= $rParentID) {
			$rCurrentSource = trim($rData['stream_source'], '\'"');
		} else {
			$rCurrentSource = 'Loopback: #' . $rParentID;
		}
		$rOffset = $rData['offset'];
		$rStreamProbe = true;
		echo "Stream started\n";
		echo $rCurrentSource . "\n";
		if ($rPrioritySwitch) {
			$rForceSource = null;
			$rPrioritySwitch = false;
		}
		if (!$rDelay) {
			$rFolder = STREAMS_PATH;
		} else {
			$rFolder = DELAY_PATH;
		}
		$e1bc98ce34937596 = $rFolder . $rStreamID . '_0.ts';
		$ea6de21e70c530a9 = false;
		$rChecks = 0;
		$A63c815f93524582 = (($rSegmentTime * 3) <= 30 ? $rSegmentTime * 3 : 30);
		if (!($A63c815f93524582 < 20)) {
			goto label998;
		}
		$A63c815f93524582 = 20;
		goto label998;
		label998:
		if (true) {
			echo 'Checking for playlist ' . ($rChecks + 1) . '/' . $A63c815f93524582 . "...\n";
			if (ProcessManager::isStreamRunning($rPID, $rStreamID)) {
				if (file_exists($rPlaylist)) {
					echo "Playlist exists!\n";
					goto label1064;
				}
				if ((file_exists($e1bc98ce34937596) && !$ea6de21e70c530a9 && $rStreamInfo['on_demand'])) {
					echo "Segment exists!\n";
					$ea6de21e70c530a9 = true;
					$rChecks = 0;
					$db->query('UPDATE `streams_servers` SET `stream_status` = 0, `stream_started` = ? WHERE `server_stream_id` = ?', time() - $rOffset, $rStreamInfo['server_stream_id']);
				}
				if (($rChecks == $A63c815f93524582)) {
					echo "Reached max failures\n";
					$E9d347a502b13abd = true;
					goto label1064;
				}
				$rChecks++;
				sleep(1);
				goto label998;
			}
			echo "Ffmpeg stopped running\n";
			$E9d347a502b13abd = true;
			goto label1064;
		}
		goto label1064;
		label1064:
		goto label562;
		label562:
		SettingsManager::set(SettingsRepository::getAll());
		if (ProcessManager::isStreamRunning($rPID, $rStreamID) && !$E9d347a502b13abd) {
			echo "Started! Probe Stream\n";
			if ($rFirstRun) {
				$rFirstRun = false;
				StreamProcess::streamLog($rStreamID, SERVER_ID, 'STREAM_START', $rCurrentSource);
			} else {
				StreamProcess::streamLog($rStreamID, SERVER_ID, 'STREAM_RESTART', $rCurrentSource);
			}
			$rSegment = $rFolder . StreamUtils::getPlaylistSegments($rPlaylist, 10)[0];
			$rStreamInfo['stream_info'] = null;
			if (file_exists($rSegment)) {
				$E02429d2ee600884 = FFprobeRunner::probeStream($rSegment);
				if ((10 < intval($E02429d2ee600884['of_duration']))) {
					$E02429d2ee600884['of_duration'] = 10;
				}
				file_put_contents(STREAMS_PATH . $rStreamID . '_.dur', intval($E02429d2ee600884['of_duration']));
				if (($rSegmentTime < intval($E02429d2ee600884['of_duration']))) {
					$rSegmentTime = intval($E02429d2ee600884['of_duration']);
				}
				if ($E02429d2ee600884) {
					$rStreamInfo['stream_info'] = json_encode($E02429d2ee600884, JSON_UNESCAPED_UNICODE);
					$rBitrate = StreamUtils::getStreamBitrate('live', STREAMS_PATH . $rStreamID . '_.m3u8');
					$rStreamProbe = false;
					$rDurationChecked = time();
				}
			}

			// Defining video/Audio parameters
			$rCompatible = 0;
			$rAudioCodec = $rVideoCodec = $rResolution = null;
			if ($rStreamInfo['stream_info']) {
				$rStreamJSON = json_decode($rStreamInfo['stream_info'], true);
				$rCompatible = is_array($rStreamJSON) ? intval(DiagnosticsService::checkCompatibility($rStreamJSON, SettingsManager::getAll()['player_allow_hevc'])) : 0;
				if (is_array($rStreamJSON) && isset($rStreamJSON['codecs']) && is_array($rStreamJSON['codecs'])) {
					$rAudioCodec = isset($rStreamJSON['codecs']['audio']['codec_name']) ? $rStreamJSON['codecs']['audio']['codec_name'] : null;
					$rVideoCodec = isset($rStreamJSON['codecs']['video']['codec_name']) ? $rStreamJSON['codecs']['video']['codec_name'] : null;
					$rResolution = isset($rStreamJSON['codecs']['video']['height']) ? $rStreamJSON['codecs']['video']['height'] : null;
				}
				if ($rResolution) {
					$rResolution = StreamSorter::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
				}
			}

			if (!$ea6de21e70c530a9 && $rStreamInfo['stream_info'] && $rStreamInfo['on_demand']) {
				if ($rStreamInfo['stream_info']) {
					$db->query('UPDATE `streams_servers` SET `stream_info` = ?, `compatible` = ?, `audio_codec` = ?, `video_codec` = ?, `resolution` = ?, `bitrate` = ?, `stream_status` = 0, `stream_started` = ? WHERE `server_stream_id` = ?', $rStreamInfo['stream_info'], $rCompatible, $rAudioCodec, $rVideoCodec, $rResolution, intval($rBitrate), time() - $rOffset, $rStreamInfo['server_stream_id']);
				} else {
					$db->query('UPDATE `streams_servers` SET `stream_status` = 0, `stream_info` = NULL, `compatible` = 0, `audio_codec` = NULL, `video_codec` = NULL, `resolution` = NULL, `stream_started` = ? WHERE `server_stream_id` = ?', time() - $rOffset, $rStreamInfo['server_stream_id']);
				}
			} else {
				$db->query('UPDATE `streams_servers` SET `stream_info` = ?, `compatible` = ?, `audio_codec` = ?, `video_codec` = ?, `resolution` = ?, `bitrate` = ?, `stream_status` = 0 WHERE `server_stream_id` = ?', $rStreamInfo['stream_info'], $rCompatible, $rAudioCodec, $rVideoCodec, $rResolution, intval($rBitrate), $rStreamInfo['server_stream_id']);
			}
			if (SettingsManager::getAll()['enable_cache']) {
				StreamProcess::updateStream($rStreamID);
			}
			echo "End start process\n";
			goto label554;
		}
		echo "Stream start failed...\n";
		if (($rParentID == 0)) {
			StreamProcess::streamLog($rStreamID, SERVER_ID, 'STREAM_START_FAIL', $rCurrentSource);
		}
		if ((is_numeric($rPID) && (0 < $rPID) && ProcessManager::isStreamRunning($rPID, $rStreamID))) {
			shell_exec('kill -9 ' . intval($rPID));
		}
		$db->query('UPDATE `streams_servers` SET `pid` = null, `stream_status` = 1 WHERE `server_stream_id` = ?;', $rStreamInfo['server_stream_id']);
		if (SettingsManager::getAll()['enable_cache']) {
			StreamProcess::updateStream($rStreamID);
		}
		echo 'Sleep for ' . SettingsManager::getAll()['stream_fail_sleep'] . " seconds...";
		sleep(SettingsManager::getAll()['stream_fail_sleep']);
		if (!(SettingsManager::getAll()['on_demand_failure_exit'] && $rStreamInfo['on_demand'])) {
			goto label554;
		}
		echo "On-demand failed to run!\n";
		return 0;
		label1186:
		if ($D97a4f098a8d1bf8) {
			StreamProcess::streamLog($rStreamID, SERVER_ID, 'STREAM_FAILED', $rCurrentSource);
			echo "Stream failed!\n";
		}
		$db->db_connect();
		goto label471;
		label471:
		if (ProcessManager::isStreamRunning($rPID, $rStreamID)) {
			echo "Killing stream...\n";
			if ((is_numeric($rPID) && (0 < $rPID))) {
				shell_exec('kill -9 ' . intval($rPID));
			}
			usleep(50000);
		}
		if (ProcessManager::isNamedProcessRunning($rDelayPID, 'XC_VMDelay', $rStreamID)) {
			echo "Killing stream delay...\n";
			if ((is_numeric($rDelayPID) && (0 < $rDelayPID))) {
				shell_exec('kill -9 ' . intval($rDelayPID));
			}
			usleep(50000);
		}
		goto label76;
		label554:
		if ((MONITOR_CALLS <= $rTotalCalls)) {
			$rTotalCalls = 0;
		}
		goto label76;
		label76:
		if (!ProcessManager::isStreamRunning($rPID, $rStreamID)) {
			$E9d347a502b13abd = false;
			echo "Restarting...\n";
			shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*');
			file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor', getmypid());
			$rOffset = 0;
			$rTotalCalls++;
			if ((0 < $rStreamInfo['parent_id']) && SettingsManager::getAll()['php_loopback']) {
				goto label1127;
			}
			if ((0 < $rStreamInfo['llod']) && $rStreamInfo['on_demand'] && $rFirstRun) {
				goto label933;
			}
			if ($rStreamInfo['type'] == 3) {
				if (((0 < $rPID) && !$rStreamInfo['parent_id'] && (0 < $rStreamInfo['stream_started']))) {
					$rCCInfo = json_decode($rStreamInfo['cc_info'], true);
					if (($rCCInfo && ((time() - $rStreamInfo['stream_started']) < (intval($rCCInfo[count($rCCInfo) - 1]['finish']) * 0.95)))) {
						$rOffset = time() - $rStreamInfo['stream_started'];
					}
				}
				$rData = StreamProcess::startStream($rStreamID, false, $rForceSource, false, $rOffset);
				label933:
				if ($rStreamInfo['llod'] == 1) {
					goto label1512;
				}
				goto label1;
			}
			$rData = StreamProcess::startStream($rStreamID, $rTotalCalls < MONITOR_CALLS, $rForceSource);
			goto label644;
		}
		goto label235;
		label1087:
		if (SettingsManager::getAll()['fps_check_type'] == 1) {
			goto label1094;
		}
		$b4015d24aedaf0db = $d75674a646265e7b;
		goto label1847;
		label1094:
		$rSegment = StreamUtils::getPlaylistSegments($rPlaylist, 10)[0];
		if (empty($rSegment)) {
			goto label1847;
		}
		$E02429d2ee600884 = FFprobeRunner::probeStream($rFolder . $rSegment);
		if (!(isset($E02429d2ee600884['codecs']['video']['avg_frame_rate']) || isset($E02429d2ee600884['codecs']['video']['r_frame_rate']))) {
			goto label1847;
		}
		$d75674a646265e7b = $E02429d2ee600884['codecs']['video']['avg_frame_rate'] ?: $E02429d2ee600884['codecs']['video']['r_frame_rate'];
		goto label768;
		label768:
		if (stripos($d75674a646265e7b, '/') !== false) {
			goto label780;
		}
		$d75674a646265e7b = floatval($d75674a646265e7b);
		goto label1052;
		label780:
		list($Be71401a913607c0, $Cd98e5a46a318d0a) = array_map('floatval', explode('/', $d75674a646265e7b));
		goto label1047;
		label1047:
		$d75674a646265e7b = floatval($Be71401a913607c0 / $Cd98e5a46a318d0a);
		label1052:
		if (!(0 < $d75674a646265e7b)) {
			goto label1057;
		}
		$b4015d24aedaf0db = $d75674a646265e7b;
		label1057:
		goto label1847;

		label1847:
		unlink(STREAMS_PATH . $rStreamID . '_.progress_check');
		label298:
		if (!((SettingsManager::getAll()['audio_restart_loss'] == 1) && (300 < (time() - $rAudioChecked)))) {
			goto label617;
		}
		echo "Checking audio...\n";
		$rSegment = StreamUtils::getPlaylistSegments($rPlaylist, 10)[0];
		if (!empty($rSegment)) {
			$E02429d2ee600884 = FFprobeRunner::probeStream($rFolder . $rSegment);
			if ((!isset($E02429d2ee600884['codecs']['audio']) || empty($E02429d2ee600884['codecs']['audio']))) {
				echo "Lost audio! Break\n";
				StreamProcess::streamLog($rStreamID, SERVER_ID, 'AUDIO_LOSS', $rCurrentSource);
				goto label1186;
			}
			$rAudioChecked = time();
			label617:
			if ((($rSegmentTime * 6) <= time() - $rCheckedTime)) {
				$Fcfb63b23cad3c6e = md5_file($rPlaylist);
				if ($rMD5 != $Fcfb63b23cad3c6e) {
					$rMD5 = $Fcfb63b23cad3c6e;
					$rCheckedTime = time();
					label1851:
					if (SettingsManager::getAll()['encrypt_hls']) {
						foreach (glob(STREAMS_PATH . $rStreamID . '_*.ts.enc') as $rFile) {
							if (!file_exists(rtrim($rFile, '.enc'))) {
								unlink($rFile);
							}
						}
					}
					if ((!is_array(json_decode($rStreamInfo['stream_info'], true)) || count(json_decode($rStreamInfo['stream_info'], true)) == 0)) {
						$rStreamProbe = true;
					}
					$rCheckedTime = time();
					goto label1095;
				}
				goto label1186;
			}
			label1095:
			if (((SettingsManager::getAll()['priority_backup'] == 1) && (1 < count($rSources)) && ($rParentID == 0) && (300 < (time() - $rBackupsChecked)))) {
				echo "Checking backups...\n";
				$rBackupsChecked = time();
				$rKey = array_search($rCurrentSource, $rSources);
				if ((!is_numeric($rKey) || (0 < $rKey))) {
					foreach ($rSources as $rSource) {
						if (!(($rSource == $rCurrentSource) || ($rSource == $rForceSource))) {
							$rStreamSource = StreamUtils::parseStreamURL($rSource);
							$rProtocol = strtolower(substr($rStreamSource, 0, strpos($rStreamSource, '://')));
							$rArguments = implode(' ', StreamUtils::getArguments($rStreamArguments, $rProtocol, 'fetch'));
							if (($E02429d2ee600884 = FFprobeRunner::probeStream($rStreamSource, $rArguments))) {
								echo "Switch priority\n";
								StreamProcess::streamLog($rStreamID, SERVER_ID, 'PRIORITY_SWITCH', $rSource);
								$rForceSource = $rSource;
								$rPrioritySwitch = true;
								$D97a4f098a8d1bf8 = false;
								goto label1186;
							}
						}
					}
				}
			}
			goto label496;
		}
		goto label1186;
		label1880:

		return 0;
	}

	private function checkRunning(int $rStreamID): void {
		clearstatcache(true);
		$rPID = 0;
		$monitorFile = STREAMS_PATH . $rStreamID . '_.monitor';

		if (file_exists($monitorFile)) {
			$rPID = intval(file_get_contents($monitorFile));
		}

		if (empty($rPID)) {
			shell_exec("ps -ef | grep 'XC_VM\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}' | xargs -r kill -9 2>/dev/null");
		} else {
			if (file_exists('/proc/' . $rPID)) {
				$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
				if ($rCommand == 'XC_VM[' . $rStreamID . ']' && is_numeric($rPID) && $rPID > 0) {
					posix_kill($rPID, 9);
				}
			}
		}
	}
}
