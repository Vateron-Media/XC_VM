<?php

/**
 * InternalApiController — internal api controller
 *
 * @package XC_VM_Public_Controllers_Api
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class InternalApiController {
	private $deny = true;

	public function shutdown() {
		global $db;

		if ($this->deny) {
			BruteforceGuard::checkFlood();
		}

		if (is_object($db)) {
			$db->close_mysql();
		}
	}

	public function index() {
		set_time_limit(0);
		$rRequest = RequestManager::getAll();
		$rSettings = SettingsManager::getAll();

		if (empty($rRequest['password']) || $rRequest['password'] != $rSettings['live_streaming_pass']) {
			generateError('INVALID_API_PASSWORD');
		}

		unset($rRequest['password']);

		if (!in_array($_SERVER['REMOTE_ADDR'], ServerRepository::getAllowedIPs())) {
			generateError('API_IP_NOT_ALLOWED');
		}

		header('Access-Control-Allow-Origin: *');
		$rAction = !empty($rRequest['action']) ? $rRequest['action'] : '';
		$this->deny = false;

		$this->dispatch($rAction, $rRequest, $rSettings);
	}

	private function dispatch($rAction, $rRequest, $rSettings) {
		switch ($rAction) {
			case 'view_log':
				if (empty($rRequest['stream_id'])) {
					break;
				}

				$rStreamID = intval($rRequest['stream_id']);

				if (file_exists(STREAMS_PATH . $rStreamID . '.errors')) {
					echo file_get_contents(STREAMS_PATH . $rStreamID . '.errors');
				} elseif (file_exists(VOD_PATH . $rStreamID . '.errors')) {
					echo file_get_contents(VOD_PATH . $rStreamID . '.errors');
				}

				exit();

			case 'fpm_status':
				echo file_get_contents('http://127.0.0.1:' . ServerRepository::getAll()[SERVER_ID]['http_broadcast_port'] . '/status');

				break;

			case 'reload_epg':
				shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:epg >/dev/null 2>/dev/null &');

				break;

			case 'restore_images':
				shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php tools images >/dev/null 2>/dev/null &');

				break;

			case 'reload_nginx':
				shell_exec(BIN_PATH . 'nginx_rtmp/sbin/nginx_rtmp -s reload');
				shell_exec(BIN_PATH . 'nginx/sbin/nginx -s reload');

				break;

			case 'streams_ramdisk':
				set_time_limit(30);
				$rReturn = array('result' => true, 'streams' => array());
				exec('ls -l ' . STREAMS_PATH, $rFiles);

				foreach ($rFiles as $rFile) {
					$rSplit = explode(' ', preg_replace('!\\s+!', ' ', $rFile));
					$rFileSplit = explode('_', $rSplit[count($rSplit) - 1]);

					if (count($rFileSplit) != 2) {
						continue;
					}

					$rStreamID = intval($rFileSplit[0]);
					$rFileSize = intval($rSplit[4]);

					if (0 < $rStreamID & 0 < $rFileSize) {
						$rReturn['streams'][$rStreamID] += $rFileSize;
					}
				}

				echo json_encode($rReturn);

				exit();

			case 'vod':
				if (!empty($rRequest['stream_ids']) && !empty($rRequest['function'])) {
					$rStreamIDs = array_map('intval', $rRequest['stream_ids']);
					$rFunction = $rRequest['function'];

					switch ($rFunction) {
						case 'start':
							foreach ($rStreamIDs as $rStreamID) {
								StreamProcess::stopMovie($rStreamID, true);

								if (isset($rRequest['force']) && $rRequest['force']) {
									StreamProcess::startMovie($rStreamID);
								} else {
									StreamProcess::queueMovie($rStreamID);
								}
							}

							echo json_encode(array('result' => true));

							exit();

						case 'stop':
							foreach ($rStreamIDs as $rStreamID) {
								StreamProcess::stopMovie($rStreamID);
							}

							echo json_encode(array('result' => true));

							exit();
					}
				}

				// no break
			case 'rtmp_stats':
				echo json_encode(ServerRepository::getLocalRTMPStats());

				break;

			case 'kill_pid':
				$rPID = intval($rRequest['pid']);

				if (0 < $rPID) {
					posix_kill($rPID, 9);
					echo json_encode(array('result' => true));
				} else {
					echo json_encode(array('result' => false));
				}

				break;

			case 'rtmp_kill':
				$rName = $rRequest['name'];
				shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . ServerRepository::getAll()[SERVER_ID]['rtmp_mport_url'] . 'control/drop/publisher?app=live&name=' . escapeshellcmd($rName) . '" >/dev/null 2>/dev/null &');
				echo json_encode(array('result' => true));

				exit();

			case 'stream':
				if (!empty($rRequest['stream_ids']) && !empty($rRequest['function'])) {
					$rStreamIDs = array_map('intval', $rRequest['stream_ids']);
					$rFunction = $rRequest['function'];

					switch ($rFunction) {
						case 'start':
							foreach ($rStreamIDs as $rStreamID) {
								if (StreamProcess::startMonitor($rStreamID, true)) {
									usleep(50000);
								} else {
									echo json_encode(array('result' => false));

									exit();
								}
							}

							echo json_encode(array('result' => true));

							exit();

						case 'stop':
							foreach ($rStreamIDs as $rStreamID) {
								StreamProcess::stopStream($rStreamID, true);
							}

							echo json_encode(array('result' => true));

							exit();

						default:
							break;
					}
				}

				// no break
			case 'stats':
				echo json_encode(SystemInfo::getStats());

				exit();

			case 'force_stream':
				$rStreamID = intval($rRequest['stream_id']);
				$rForceID = intval($rRequest['force_id']);

				if ($rStreamID > 0) {
					file_put_contents(SIGNALS_TMP_PATH . $rStreamID . '.force', $rForceID);
				}

				exit(json_encode(array('result' => true)));

			case 'closeConnection':
				ConnectionTracker::closeConnection(intval($rRequest['activity_id']));

				exit(json_encode(array('result' => true)));

			case 'pidsAreRunning':
				if (empty($rRequest['pids']) || !is_array($rRequest['pids']) || empty($rRequest['program'])) {
					break;
				}

				$rPIDs = array_map('intval', $rRequest['pids']);
				$rProgram = $rRequest['program'];
				$rOutput = array();

				foreach ($rPIDs as $rPID) {
					$rOutput[$rPID] = false;

					if (file_exists('/proc/' . $rPID) && is_readable('/proc/' . $rPID . '/exe') && strpos(basename(readlink('/proc/' . $rPID . '/exe')), basename($rProgram)) === 0) {
						$rOutput[$rPID] = true;
					}
				}

				echo json_encode($rOutput);

				exit();

			case 'getFile':
				$this->serveFile($rRequest, $rSettings);

				break;

			case 'scandir_recursive':
				set_time_limit(30);
				$rDirectory = urldecode($rRequest['dir']);
				$rAllowed = !empty($rRequest['allowed']) ? urldecode($rRequest['allowed']) : null;

				if (!file_exists($rDirectory)) {
					exit(json_encode(array('result' => false)));
				}

				if ($rAllowed) {
					$rCommand = '/usr/bin/find ' . escapeshellarg($rDirectory) . ' -regex ".*\\.\\(' . escapeshellcmd($rAllowed) . '\\)"';
				} else {
					$rCommand = '/usr/bin/find ' . escapeshellarg($rDirectory);
				}

				exec($rCommand, $rReturn);
				echo json_encode($rReturn, JSON_UNESCAPED_UNICODE);

				exit();

			case 'scandir':
				set_time_limit(30);
				$rDirectory = urldecode($rRequest['dir']);
				$rAllowed = !empty($rRequest['allowed']) ? explode('|', urldecode($rRequest['allowed'])) : array();

				if (!file_exists($rDirectory)) {
					exit(json_encode(array('result' => false)));
				}

				$rReturn = array('result' => true, 'dirs' => array(), 'files' => array());
				$rFiles = scanDir($rDirectory);

				foreach ($rFiles as $rKey => $rValue) {
					if (in_array($rValue, array('.', '..'))) {
						continue;
					}

					if (is_dir($rDirectory . '/' . $rValue)) {
						$rReturn['dirs'][] = $rValue;
					} else {
						$rExt = strtolower(pathinfo($rValue, PATHINFO_EXTENSION));

						if (is_array($rAllowed) && !in_array($rExt, $rAllowed) && $rAllowed) {
							continue;
						}

						$rReturn['files'][] = $rValue;
					}
				}

				echo json_encode($rReturn);

				exit();

			case 'get_free_space':
				exec('df -h', $rReturn);
				echo json_encode($rReturn);

				exit();

			case 'get_pids':
				exec('ps -e -o user,pid,%cpu,%mem,vsz,rss,tty,stat,time,etime,command', $rReturn);
				echo json_encode($rReturn);

				exit();

			case 'redirect_connection':
				if (!empty($rRequest['uuid']) || !empty($rRequest['stream_id'])) {
					RequestManager::update('type', 'redirect');
					file_put_contents(SIGNALS_PATH . $rRequest['uuid'], json_encode($rRequest));
				}

				break;

			case 'free_temp':
				exec('rm -rf ' . MAIN_HOME . 'tmp/*');
				shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache');
				echo json_encode(array('result' => true));

				break;

			case 'free_streams':
				exec('rm ' . MAIN_HOME . 'content/streams/*');
				echo json_encode(array('result' => true));

				break;

			case 'signal_send':
				if (!empty($rRequest['message']) && !empty($rRequest['uuid'])) {
					RequestManager::update('type', 'signal');
					file_put_contents(SIGNALS_PATH . $rRequest['uuid'], json_encode($rRequest));
				}

				break;

			case 'get_certificate_info':
				echo json_encode(DiagnosticsService::getCertificateInfo());

				exit();

			case 'watch_force':
				shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:watch ' . intval($rRequest['id']) . ' >/dev/null 2>/dev/null &');

				break;

			case 'plex_force':
				shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:plex ' . intval($rRequest['id']) . ' >/dev/null 2>/dev/null &');

				break;

			case 'get_archive_files':
				$rStreamID = intval($rRequest['stream_id']);
				echo json_encode(array('result' => true, 'data' => glob(ARCHIVE_PATH . $rStreamID . '/*.ts')));

				exit();

			case 'kill_watch':
				$this->killProcessGroup(CACHE_TMP_PATH . 'watch_pid', WATCH_TMP_PATH . '*.wpid');

				exit(json_encode(array('result' => true)));

			case 'kill_plex':
				$this->killProcessGroup(CACHE_TMP_PATH . 'plex_pid', WATCH_TMP_PATH . '*.ppid');

				exit(json_encode(array('result' => true)));

			case 'probe':
				$this->probeStream($rRequest);

				break;

			default:
				exit(json_encode(array('result' => false)));
		}
	}

	private function killProcessGroup($rPidFile, $rGlobPattern) {
		if (file_exists($rPidFile)) {
			$rPrevPID = intval(file_get_contents($rPidFile));
		} else {
			$rPrevPID = null;
		}

		if ($rPrevPID && ProcessManager::isRunning($rPrevPID, 'php')) {
			shell_exec('kill -9 ' . $rPrevPID);
		}

		$rPIDs = glob($rGlobPattern);

		foreach ($rPIDs as $rPIDFile) {
			$rExt = pathinfo($rGlobPattern, PATHINFO_EXTENSION);
			$rPID = intval(basename($rPIDFile, '.' . $rExt));

			if ($rPID && ProcessManager::isRunning($rPID, 'php')) {
				shell_exec('kill -9 ' . $rPID);
			}

			unlink($rPIDFile);
		}
	}

	private function serveFile($rRequest, $rSettings) {
		if (empty($rRequest['filename'])) {
			exit(json_encode(array('result' => false)));
		}

		$rFilename = urldecode($rRequest['filename']);
		$rFilename = trim($rFilename, "'\"\\");

		if (!in_array(strtolower(pathinfo($rFilename)['extension']), array('log', 'tar.gz', 'gz', 'zip', 'm3u8', 'mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts', 'srt', 'sub', 'sbv', 'jpg', 'png', 'bmp', 'jpeg', 'gif', 'tif'))) {
			exit(json_encode(array('result' => false, 'error' => 'Invalid file extension.')));
		}

		if (!file_exists($rFilename) || !is_readable($rFilename)) {
			exit(json_encode(array('result' => false, 'error' => 'Invalid file extension.')));
		}

		header('Content-Type: application/octet-stream');
		$rFP = @fopen($rFilename, 'rb');
		clearstatcache();
		$rSize = filesize($rFilename);
		$rLength = $rSize;
		$rStart = 0;
		$rEnd = $rSize - 1;
		header('Accept-Ranges: bytes');

		if (isset($_SERVER['HTTP_RANGE'])) {
			$rRangeEnd = $rEnd;
			list(, $rRange) = explode('=', $_SERVER['HTTP_RANGE'], 2);

			if (strpos($rRange, ',') === false) {
				if ($rRange == '-') {
					$rRangeStart = $rSize - substr($rRange, 1);
				} else {
					$rRange = explode('-', $rRange);
					$rRangeStart = $rRange[0];
					$rRangeEnd = isset($rRange[1]) && is_numeric($rRange[1]) ? $rRange[1] : $rSize;
				}

				$rRangeEnd = $rEnd < $rRangeEnd ? $rEnd : $rRangeEnd;

				if ($rRangeEnd < $rRangeStart || $rSize - 1 < $rRangeStart || $rSize <= $rRangeEnd) {
					header('HTTP/1.1 416 Requested Range Not Satisfiable');
					header('Content-Range: bytes ' . $rStart . '-' . $rEnd . '/' . $rSize);

					exit();
				}

				$rStart = $rRangeStart;
				$rEnd = $rRangeEnd;
				$rLength = $rEnd - $rStart + 1;
				fseek($rFP, $rStart);
				header('HTTP/1.1 206 Partial Content');
			} else {
				header('HTTP/1.1 416 Requested Range Not Satisfiable');
				header('Content-Range: bytes ' . $rStart . '-' . $rEnd . '/' . $rSize);

				exit();
			}
		}

		header('Content-Range: bytes ' . $rStart . '-' . $rEnd . '/' . $rSize);
		header('Content-Length: ' . $rLength);

		$sent = 0;

		while ($sent < $rLength && !feof($rFP)) {
			$buffer = fread($rFP, intval($rSettings['read_buffer_size']) ?: 8192);
			$sent += strlen($buffer);
			echo $buffer;
			flush();
		}

		fclose($rFP);

		exit();
	}

	private function probeStream($rRequest) {
		if (empty($rRequest['url'])) {
			exit(json_encode(array('result' => false)));
		}

		$rURL = $rRequest['url'];
		$rFetchArguments = array();

		if (!empty($rRequest['user_agent'])) {
			$rFetchArguments[] = sprintf("-user_agent '%s'", escapeshellcmd($rRequest['user_agent']));
		}

		if (!empty($rRequest['http_proxy'])) {
			$rFetchArguments[] = sprintf("-http_proxy '%s'", escapeshellcmd($rRequest['http_proxy']));
		}

		if (!empty($rRequest['cookies'])) {
			$rFetchArguments[] = sprintf("-cookies '%s'", escapeshellcmd($rRequest['cookies']));
		}

		$rHeaders = !empty($rRequest['headers']) ? rtrim($rRequest['headers'], "\r\n") . "\r\n" : '';
		$rHeaders .= 'X-XC_VM-Prebuffer:1' . "\r\n";
		$rFetchArguments[] = sprintf('-headers %s', escapeshellarg($rHeaders));

		exit(json_encode(array('result' => true, 'data' => FFprobeRunner::probeStream($rURL, $rFetchArguments, '', false))));
	}
}
