<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Util\StreamUtils;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSorter;
use XcVm\Streaming\Codec\FFprobeRunner;

/**
 * `monitor <stream_id> [restart]` — the per-stream watchdog.
 *
 * Runs one long-lived supervisor loop per stream (as the `xc_vm` user): it
 * (re)starts the stream's ffmpeg, waits for the HLS playlist to appear, probes
 * the first segment for codec/duration metadata, then watches the running
 * stream and restarts it on ffmpeg death, an FPS drop, audio loss, a stale
 * playlist, a scheduled auto-restart, or a priority/forced source switch.
 *
 * Once an obfuscated goto/label state machine; since untangled into structured
 * while-loops backed by the small probe/codec/fps helpers at the bottom.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MonitorCommand implements CommandInterface {

	/** {@inheritDoc} */
	public function getName(): string {
		return 'monitor';
	}

	/** {@inheritDoc} */
	public function getDescription(): string {
		return 'Monitor stream by ID (start/restart/track)';
	}

	/**
	 * Supervise stream `$rArgs[0]`: load its config, then loop forever —
	 * start/restart the ffmpeg process, wait for its playlist, probe it, and
	 * monitor health — until an exit condition (failure limit, missing stream,
	 * on-demand give-up) returns.
	 *
	 * Must run as the `xc_vm` user.
	 *
	 * @param array $rArgs [0 => stream id, 1 => optional truthy "restart" flag].
	 * @return int Exit code: 0 = clean stop, 1 = wrong user.
	 */
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

		// ── Supervisor + monitor loop (restructured from goto state machine) ──
		while (true) {
			if (0 < $rPID) {
				$db->close_mysql();
				$rStartedTime = $rDurationChecked = $rAudioChecked = $rCheckedTime = $rBackupsChecked = time();
				$rMD5 = file_exists($rPlaylist) ? md5_file($rPlaylist) : false;
				$rStreamFailed = ProcessManager::isStreamRunning($rPID, $rStreamID) && file_exists($rPlaylist);
				$rBaselineFps = null;
				while (ProcessManager::isStreamRunning($rPID, $rStreamID) && file_exists($rPlaylist)) {
					if (self::isAutoRestartDue($rAutoRestart)) {
						echo "Auto-restart\n";
						StreamProcess::streamLog($rStreamID, SERVER_ID, 'AUTO_RESTART', $rCurrentSource);
						$rStreamFailed = false;
						break;
					}
					if (($rStreamProbe || (!file_exists(STREAMS_PATH . $rStreamID . '_.dur') && (300 < (time() - $rDurationChecked))))) {
						echo "Probe Stream\n";
						$rSegment = StreamUtils::getPlaylistSegments($rPlaylist, 10)[0];
						if (!empty($rSegment)) {
							if (((300 < (time() - $rDurationChecked)) && ($rSegment == $rLastSegment))) {
								StreamProcess::streamLog($rStreamID, SERVER_ID, 'FFMPEG_ERROR', $rCurrentSource);
								break;
							}
							$rLastSegment = $rSegment;
							$rProbe = FFprobeRunner::probeStream($rFolder . $rSegment);
							list($rProbe, $rSegmentTime) = self::persistSegmentDuration($rProbe, $rStreamID, $rSegmentTime);
							file_put_contents(STREAMS_PATH . $rStreamID . '_.stream_info', json_encode($rProbe, JSON_UNESCAPED_UNICODE));
							$rStreamInfo['stream_info'] = json_encode($rProbe, JSON_UNESCAPED_UNICODE);
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
					if (($rStreamInfo['fps_restart'] == 1) && (SettingsManager::getAll()['fps_delay'] < (time() - $rStartedTime)) && file_exists(STREAMS_PATH . $rStreamID . '_.progress_check')) {
						echo "Checking FPS...\n";
						$rFps = floatval(json_decode(file_get_contents(STREAMS_PATH . $rStreamID . '_.progress_check'), true)['fps']) ?: 0;
						if (0 < $rFps) {
							if (!$rBaselineFps) {
								if (SettingsManager::getAll()['fps_check_type'] == 1) {
									$rSegment = StreamUtils::getPlaylistSegments($rPlaylist, 10)[0];
									if (!empty($rSegment)) {
										$rProbe = FFprobeRunner::probeStream($rFolder . $rSegment);
										if (isset($rProbe['codecs']['video']['avg_frame_rate']) || isset($rProbe['codecs']['video']['r_frame_rate'])) {
											$rFps = $rProbe['codecs']['video']['avg_frame_rate'] ?: $rProbe['codecs']['video']['r_frame_rate'];
											$rFps = self::parseFrameRate($rFps);
											if (0 < $rFps) {
												$rBaselineFps = $rFps;
											}
										}
									}
								} else {
									$rBaselineFps = $rFps;
								}
							} elseif ($rBaselineFps && (($rFps * ($rStreamInfo['fps_threshold'] ?: 100)) < $rBaselineFps)) {
								echo "FPS dropped below threshold! Break\n";
								StreamProcess::streamLog($rStreamID, SERVER_ID, 'FPS_DROP_THRESHOLD', $rCurrentSource);
								break;
							}
						}
						unlink(STREAMS_PATH . $rStreamID . '_.progress_check');
					}
					if ((SettingsManager::getAll()['audio_restart_loss'] == 1) && (300 < (time() - $rAudioChecked))) {
						echo "Checking audio...\n";
						$rSegment = StreamUtils::getPlaylistSegments($rPlaylist, 10)[0];
						if (!empty($rSegment)) {
							$rProbe = FFprobeRunner::probeStream($rFolder . $rSegment);
							if ((!isset($rProbe['codecs']['audio']) || empty($rProbe['codecs']['audio']))) {
								echo "Lost audio! Break\n";
								StreamProcess::streamLog($rStreamID, SERVER_ID, 'AUDIO_LOSS', $rCurrentSource);
								break;
							}
							$rAudioChecked = time();
						} else {
							break;
						}
					}
					if (($rSegmentTime * 6) <= time() - $rCheckedTime) {
						$rNewMd5 = md5_file($rPlaylist);
						if ($rMD5 != $rNewMd5) {
							$rMD5 = $rNewMd5;
							$rCheckedTime = time();
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
						} else {
							break;
						}
					}
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
									if (($rProbe = FFprobeRunner::probeStream($rStreamSource, $rArguments))) {
										echo "Switch priority\n";
										StreamProcess::streamLog($rStreamID, SERVER_ID, 'PRIORITY_SWITCH', $rSource);
										$rForceSource = $rSource;
										$rPrioritySwitch = true;
										$rStreamFailed = false;
										break 2;
									}
								}
							}
						}
					}
					if ((file_exists(SIGNALS_TMP_PATH . $rStreamID . '.force') && ($rParentID == 0))) {
						$rForceID = intval(file_get_contents(SIGNALS_TMP_PATH . $rStreamID . '.force'));
						$rStreamSource = StreamUtils::parseStreamURL($rSources[$rForceID]);
						if (($rSources[$rForceID] != $rCurrentSource)) {
							$rProtocol = strtolower(substr($rStreamSource, 0, strpos($rStreamSource, '://')));
							$rArguments = implode(' ', StreamUtils::getArguments($rStreamArguments, $rProtocol, 'fetch'));
							if (($rProbe = FFprobeRunner::probeStream($rStreamSource, $rArguments))) {
								echo "Force new source\n";
								StreamProcess::streamLog($rStreamID, SERVER_ID, 'FORCE_SOURCE', $rSources[$rForceID]);
								$rForceSource = $rSources[$rForceID];
								unlink(SIGNALS_TMP_PATH . $rStreamID . '.force');
								$rStreamFailed = false;
								break;
							}
						}
						unlink(SIGNALS_TMP_PATH . $rStreamID . '.force');
					}
					if (($rDelay && ($rStreamInfo['delay_available_at'] <= time()) && !ProcessManager::isNamedProcessRunning($rDelayPID, 'XC_VMDelay', $rStreamID))) {
						echo "Start Delay\n";
						StreamProcess::streamLog($rStreamID, SERVER_ID, 'DELAY_START');
						$rDelayPID = intval(shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php delay ' . intval($rStreamID) . ' ' . intval($rStreamInfo['delay_minutes']) . ' >/dev/null 2>/dev/null & echo $!'));
					}
					sleep(1);
				}
				if ($rStreamFailed) {
					StreamProcess::streamLog($rStreamID, SERVER_ID, 'STREAM_FAILED', $rCurrentSource);
					echo "Stream failed!\n";
				}
				$db->db_connect();
			}
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
			if (!ProcessManager::isStreamRunning($rPID, $rStreamID)) {
				while (true) {
					$rStartFailed = false;
					echo "Restarting...\n";
					shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*');
					file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor', getmypid());
					$rOffset = 0;
					$rTotalCalls++;
					if ((0 < $rStreamInfo['parent_id']) && SettingsManager::getAll()['php_loopback']) {
						$rData = StreamProcess::startLoopback($rStreamID);
					} elseif ((0 < $rStreamInfo['llod']) && $rStreamInfo['on_demand'] && $rFirstRun && $rStreamInfo['type'] != 3) {
						if ($rStreamInfo['llod'] == 1) {
							if ($rForceSource) {
								$rStartSource = $rForceSource;
							} else {
								$rStartSource = json_decode($rStreamInfo['stream_source'], true)[0];
							}
							$rData = StreamProcess::startStream($rStreamID, false, $rStartSource, true);
						} else {
							if ($rStreamInfo['parent_id']) {
								$rForceSource = (!is_null(ServerRepository::getAll()[SERVER_ID]['private_url_ip']) && !is_null(ServerRepository::getAll()[$rStreamInfo['parent_id']]['private_url_ip']) ? ServerRepository::getAll()[$rStreamInfo['parent_id']]['private_url_ip'] : ServerRepository::getAll()[$rStreamInfo['parent_id']]['public_url_ip']) . 'admin/live?stream=' . intval($rStreamID) . '&password=' . urlencode(SettingsManager::getAll()['live_streaming_pass']) . '&extension=ts';
							}
							$rData = StreamProcess::startLLOD($rStreamID, $rStreamInfo, $rStreamInfo['parent_id'] ? array() : $rStreamArguments, $rForceSource);
						}
					} elseif ($rStreamInfo['type'] == 3) {
						if ((0 < $rPID) && !$rStreamInfo['parent_id'] && (0 < $rStreamInfo['stream_started'])) {
							$rCCInfo = json_decode($rStreamInfo['cc_info'], true);
							if (($rCCInfo && ((time() - $rStreamInfo['stream_started']) < (intval($rCCInfo[count($rCCInfo) - 1]['finish']) * 0.95)))) {
								$rOffset = time() - $rStreamInfo['stream_started'];
							}
						}
						$rData = StreamProcess::startStream($rStreamID, false, $rForceSource, false, $rOffset);
					} else {
						$rData = StreamProcess::startStream($rStreamID, $rTotalCalls < MONITOR_CALLS, $rForceSource);
					}
					if ((is_numeric($rData) && ($rData == 0))) {
						$rStartFailed = true;
						$rMaxFails++;
						if (((0 < SettingsManager::getAll()['stop_failures']) && ($rMaxFails >= SettingsManager::getAll()['stop_failures']))) {
							echo "Failure limit reached, exiting.\n";
							return 0;
						}
						echo 'Stream start failed (attempt ' . $rMaxFails . '). Sleeping ' . SettingsManager::getAll()['stream_fail_sleep'] . " seconds...\n";
						sleep(SettingsManager::getAll()['stream_fail_sleep']);
						continue;
					}
					break;
				}
				if (!$rData) {
					return 0;
				}
				if (!$rStartFailed) {
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
					$rFirstSegment = $rFolder . $rStreamID . '_0.ts';
					$rSegmentSeen = false;
					$rChecks = 0;
					$rMaxChecks = max(20, min($rSegmentTime * 3, 30));
					while (true) {
						echo 'Checking for playlist ' . ($rChecks + 1) . '/' . $rMaxChecks . "...\n";
						if (!ProcessManager::isStreamRunning($rPID, $rStreamID)) {
							echo "Ffmpeg stopped running\n";
							$rStartFailed = true;
							break;
						}
						if (file_exists($rPlaylist)) {
							echo "Playlist exists!\n";
							break;
						}
						if ((file_exists($rFirstSegment) && !$rSegmentSeen && $rStreamInfo['on_demand'])) {
							echo "Segment exists!\n";
							$rSegmentSeen = true;
							$rChecks = 0;
							$db->query('UPDATE `streams_servers` SET `stream_status` = 0, `stream_started` = ? WHERE `server_stream_id` = ?', time() - $rOffset, $rStreamInfo['server_stream_id']);
						}
						if (($rChecks == $rMaxChecks)) {
							echo "Reached max failures\n";
							$rStartFailed = true;
							break;
						}
						$rChecks++;
						sleep(1);
					}
				}
				SettingsManager::set(SettingsRepository::getAll());
				if (ProcessManager::isStreamRunning($rPID, $rStreamID) && !$rStartFailed) {
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
						$rProbe = FFprobeRunner::probeStream($rSegment);
						list($rProbe, $rSegmentTime) = self::persistSegmentDuration($rProbe, $rStreamID, $rSegmentTime);
						if ($rProbe) {
							$rStreamInfo['stream_info'] = json_encode($rProbe, JSON_UNESCAPED_UNICODE);
							$rBitrate = StreamUtils::getStreamBitrate('live', STREAMS_PATH . $rStreamID . '_.m3u8');
							$rStreamProbe = false;
							$rDurationChecked = time();
						}
					}

					// Defining video/Audio parameters
					list($rCompatible, $rAudioCodec, $rVideoCodec, $rResolution) = self::resolveStreamCodecMeta($rStreamInfo['stream_info'], SettingsManager::getAll()['player_allow_hevc']);

					if (!$rSegmentSeen && $rStreamInfo['stream_info'] && $rStreamInfo['on_demand']) {
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
				} else {
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
					if (SettingsManager::getAll()['on_demand_failure_exit'] && $rStreamInfo['on_demand']) {
						echo "On-demand failed to run!\n";
						return 0;
					}
				}
				if ((MONITOR_CALLS <= $rTotalCalls)) {
					$rTotalCalls = 0;
				}
			}
		}
	}

	/**
	 * Parse an ffprobe frame-rate field ("30", "30000/1001", "25/1") into fps.
	 * Used by the in-loop FPS-drop check.
	 *
	 * @param mixed $rRate Raw avg_frame_rate / r_frame_rate value.
	 * @return float Frames per second; 0.0 for empty/zero/malformed input.
	 */
	private static function parseFrameRate($rRate): float {
		$rRate = (string) $rRate;
		if (strpos($rRate, '/') !== false) {
			list($rNum, $rDen) = array_map('floatval', explode('/', $rRate));
			return $rDen != 0.0 ? (float) ($rNum / $rDen) : 0.0;
		}
		return (float) $rRate;
	}

	/**
	 * Whether a stream's scheduled auto-restart is due now: the config carries
	 * days + a HH:MM time, and the current weekday/hour/minute all match.
	 *
	 * @param mixed    $rAutoRestart Decoded auto_restart config (['days'=>[...],'at'=>'HH:MM']).
	 * @param int|null $rNow         Timestamp to test against (defaults to now).
	 * @return bool
	 */
	private static function isAutoRestartDue($rAutoRestart, $rNow = null): bool {
		if (empty($rAutoRestart['days']) || empty($rAutoRestart['at'])) {
			return false;
		}
		$rNow = $rNow ?? time();
		list($rHour, $rMinutes) = explode(':', $rAutoRestart['at']);
		return in_array(date('l', $rNow), $rAutoRestart['days'])
			&& date('H', $rNow) == $rHour
			&& date('i', $rNow) == $rMinutes;
	}

	/**
	 * Derive codec metadata persisted for a running stream from its stream_info
	 * JSON: player compatibility, audio/video codec names and the resolution
	 * snapped to the nearest standard height.
	 *
	 * @param mixed $rStreamInfoJson stream_info JSON string (or falsy).
	 * @param mixed $rAllowHevc      player_allow_hevc setting.
	 * @return array{0:int,1:?string,2:?string,3:mixed} [compatible, audio, video, resolution]
	 */
	private static function resolveStreamCodecMeta($rStreamInfoJson, $rAllowHevc): array {
		$rCompatible = 0;
		$rAudioCodec = $rVideoCodec = $rResolution = null;
		if ($rStreamInfoJson) {
			$rStreamJSON = json_decode($rStreamInfoJson, true);
			$rCompatible = is_array($rStreamJSON) ? intval(DiagnosticsService::checkCompatibility($rStreamJSON, $rAllowHevc)) : 0;
			if (is_array($rStreamJSON) && isset($rStreamJSON['codecs']) && is_array($rStreamJSON['codecs'])) {
				$rAudioCodec = isset($rStreamJSON['codecs']['audio']['codec_name']) ? $rStreamJSON['codecs']['audio']['codec_name'] : null;
				$rVideoCodec = isset($rStreamJSON['codecs']['video']['codec_name']) ? $rStreamJSON['codecs']['video']['codec_name'] : null;
				$rResolution = isset($rStreamJSON['codecs']['video']['height']) ? $rStreamJSON['codecs']['video']['height'] : null;
			}
			if ($rResolution) {
				$rResolution = StreamSorter::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
			}
		}
		return array($rCompatible, $rAudioCodec, $rVideoCodec, $rResolution);
	}

	/**
	 * Clamp a probed segment's of_duration to <= 10s, persist it to the stream's
	 * _.dur file, and raise $rSegmentTime to it when the segment is longer.
	 * Shared by the in-loop probe and the post-start probe.
	 *
	 * @param mixed $rProbe       FFprobeRunner::probeStream() result.
	 * @param mixed $rStreamID    Stream id (for the _.dur path).
	 * @param mixed $rSegmentTime Current segment time.
	 * @return array{0:mixed,1:mixed} [clamped probe, updated segment time]
	 */
	private static function persistSegmentDuration($rProbe, $rStreamID, $rSegmentTime): array {
		if (10 < intval($rProbe['of_duration'])) {
			$rProbe['of_duration'] = 10;
		}
		file_put_contents(STREAMS_PATH . $rStreamID . '_.dur', intval($rProbe['of_duration']));
		if ($rSegmentTime < intval($rProbe['of_duration'])) {
			$rSegmentTime = intval($rProbe['of_duration']);
		}
		return array($rProbe, $rSegmentTime);
	}

	/**
	 * Ensure only one watchdog runs for this stream by killing any monitor
	 * already running for it: prefers the pid stored in the `_.monitor` file,
	 * falling back to matching the `XC_VM[<id>]` process title.
	 *
	 * @param int $rStreamID Stream id being monitored.
	 */
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
