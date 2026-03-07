<?php

register_shutdown_function('shutdown');
set_time_limit(0);
require 'init.php';
$rDeny = true;
loadapi();
function loadapi() {
	global $rDeny;

	if (empty(RequestManager::getAll()['password']) || RequestManager::getAll()['password'] != SettingsManager::getAll()['live_streaming_pass']) {
		generateError('INVALID_API_PASSWORD');
	}

	unset(RequestManager::getAll()['password']);

	if (!in_array($_SERVER['REMOTE_ADDR'], ServerRepository::getAllowedIPs())) {
		generateError('API_IP_NOT_ALLOWED');
	}

	header('Access-Control-Allow-Origin: *');
	$rAction = (!empty(RequestManager::getAll()['action']) ? RequestManager::getAll()['action'] : '');
	$rDeny = false;

	switch ($rAction) {
		case 'view_log':
			if (empty(RequestManager::getAll()['stream_id'])) {
				break;
			}

			$rStreamID = intval(RequestManager::getAll()['stream_id']);

			if (file_exists(STREAMS_PATH . $rStreamID . '.errors')) {
				echo file_get_contents(STREAMS_PATH . $rStreamID . '.errors');
			} else {
				if (file_exists(VOD_PATH . $rStreamID . '.errors')) {
					echo file_get_contents(VOD_PATH . $rStreamID . '.errors');
				}
			}

			exit();


		case 'fpm_status':
			echo file_get_contents('http://127.0.0.1:' . ServerRepository::getAll()[SERVER_ID]['http_broadcast_port'] . '/status');

			break;

		case 'reload_epg':
			shell_exec(PHP_BIN . ' ' . CRON_PATH . 'epg.php >/dev/null 2>/dev/null &');

			break;

		case 'restore_images':
			shell_exec(PHP_BIN . ' ' . INCLUDES_PATH . 'cli/tools.php "images" >/dev/null 2>/dev/null &');

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
				} else {
					$rStreamID = intval($rFileSplit[0]);
					$rFileSize = intval($rSplit[4]);

					if (!(0 < $rStreamID & 0 < $rFileSize)) {
					} else {
						$rReturn['streams'][$rStreamID] += $rFileSize;
					}
				}
			}
			echo json_encode($rReturn);

			exit();

		case 'vod':
			if (empty(RequestManager::getAll()['stream_ids']) || empty(RequestManager::getAll()['function'])) {
			} else {
				$rStreamIDs = array_map('intval', RequestManager::getAll()['stream_ids']);
				$rFunction = RequestManager::getAll()['function'];

				switch ($rFunction) {
					case 'start':
						foreach ($rStreamIDs as $rStreamID) {
							StreamProcess::stopMovie($rStreamID, true);

							if (isset(RequestManager::getAll()['force']) && RequestManager::getAll()['force']) {
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
			$rPID = intval(RequestManager::getAll()['pid']);

			if (0 < $rPID) {
				posix_kill($rPID, 9);
				echo json_encode(array('result' => true));
			} else {
				echo json_encode(array('result' => false));
			}

			break;

		case 'rtmp_kill':
			$rName = RequestManager::getAll()['name'];
			shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . ServerRepository::getAll()[SERVER_ID]['rtmp_mport_url'] . 'control/drop/publisher?app=live&name=' . escapeshellcmd($rName) . '" >/dev/null 2>/dev/null &');
			echo json_encode(array('result' => true));

			exit();

		case 'stream':
			if (empty(RequestManager::getAll()['stream_ids']) || empty(RequestManager::getAll()['function'])) {
			} else {
				$rStreamIDs = array_map('intval', RequestManager::getAll()['stream_ids']);
				$rFunction = RequestManager::getAll()['function'];

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
			$rStreamID = intval(RequestManager::getAll()['stream_id']);
			$rForceID = intval(RequestManager::getAll()['force_id']);

			if (0 >= $rStreamID) {
			} else {
				file_put_contents(SIGNALS_TMP_PATH . $rStreamID . '.force', $rForceID);
			}

			exit(json_encode(array('result' => true)));

		case 'closeConnection':
			ConnectionTracker::closeConnection(intval(RequestManager::getAll()['activity_id']));

			exit(json_encode(array('result' => true)));

		case 'pidsAreRunning':
			if (empty(RequestManager::getAll()['pids']) || !is_array(RequestManager::getAll()['pids']) || empty(RequestManager::getAll()['program'])) {
				break;
			}

			$rPIDs = array_map('intval', RequestManager::getAll()['pids']);
			$rProgram = RequestManager::getAll()['program'];
			$rOutput = array();

			foreach ($rPIDs as $rPID) {
				$rOutput[$rPID] = false;

				if (!(file_exists('/proc/' . $rPID) && is_readable('/proc/' . $rPID . '/exe') && strpos(basename(readlink('/proc/' . $rPID . '/exe')), basename($rProgram)) === 0)) {
				} else {
					$rOutput[$rPID] = true;
				}
			}
			echo json_encode($rOutput);

			exit();


		case 'getFile':
			if (empty(RequestManager::getAll()['filename'])) {
				break;
			}

			$rFilename = urldecode(RequestManager::getAll()['filename']);
			$rFilename = trim($rFilename, "'\"\\"); // Cut quote/backslash struck


			if (in_array(strtolower(pathinfo($rFilename)['extension']), array('log', 'tar.gz', 'gz', 'zip', 'm3u8', 'mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts', 'srt', 'sub', 'sbv', 'jpg', 'png', 'bmp', 'jpeg', 'gif', 'tif'))) {

				if (!(file_exists($rFilename) && is_readable($rFilename))) {
				} else {
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
								$rRangeEnd = (isset($rRange[1]) && is_numeric($rRange[1]) ? $rRange[1] : $rSize);
							}

							$rRangeEnd = ($rEnd < $rRangeEnd ? $rEnd : $rRangeEnd);

							if (!($rRangeEnd < $rRangeStart || $rSize - 1 < $rRangeStart || $rSize <= $rRangeEnd)) {
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
						$buffer = fread($rFP, (intval(SettingsManager::getAll()['read_buffer_size']) ?: 8192));
						$sent += strlen($buffer);
						echo $buffer;
						flush();
					}

					fclose($rFP);
				}

				exit();
			}

			exit(json_encode(array('result' => false, 'error' => 'Invalid file extension.')));

		case 'scandir_recursive':
			set_time_limit(30);
			$rDirectory = urldecode(RequestManager::getAll()['dir']);
			$rAllowed = (!empty(RequestManager::getAll()['allowed']) ? urldecode(RequestManager::getAll()['allowed']) : null);

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
			$rDirectory = urldecode(RequestManager::getAll()['dir']);
			$rAllowed = (!empty(RequestManager::getAll()['allowed']) ? explode('|', urldecode(RequestManager::getAll()['allowed'])) : array());

			if (!file_exists($rDirectory)) {
				exit(json_encode(array('result' => false)));
			}

			$rReturn = array('result' => true, 'dirs' => array(), 'files' => array());
			$rFiles = scanDir($rDirectory);

			foreach ($rFiles as $rKey => $rValue) {
				if (in_array($rValue, array('.', '..'))) {
				} else {
					if (is_dir($rDirectory . '/' . $rValue)) {
						$rReturn['dirs'][] = $rValue;
					} else {
						$rExt = strtolower(pathinfo($rValue, PATHINFO_EXTENSION));

						if (!(is_array($rAllowed) && in_array($rExt, $rAllowed)) && $rAllowed) {
						} else {
							$rReturn['files'][] = $rValue;
						}
					}
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
			if (!empty(RequestManager::getAll()['uuid']) || !empty(RequestManager::getAll()['stream_id'])) {
				RequestManager::update('type', 'redirect');
				file_put_contents(SIGNALS_PATH . RequestManager::getAll()['uuid'], json_encode(RequestManager::getAll()));
			}
			break;

		case 'free_temp':
			exec('rm -rf ' . MAIN_HOME . 'tmp/*');
			shell_exec(PHP_BIN . ' ' . CRON_PATH . 'cache.php');
			echo json_encode(array('result' => true));

			break;

		case 'free_streams':
			exec('rm ' . MAIN_HOME . 'content/streams/*');
			echo json_encode(array('result' => true));

			break;

		case 'signal_send':
			if (empty(RequestManager::getAll()['message']) || empty(RequestManager::getAll()['uuid'])) {
			} else {
				RequestManager::update('type', 'signal');
				file_put_contents(SIGNALS_PATH . RequestManager::getAll()['uuid'], json_encode(RequestManager::getAll()));
			}

			break;

		case 'get_certificate_info':
			echo json_encode(DiagnosticsService::getCertificateInfo());

			exit();

		case 'watch_force':
			shell_exec(PHP_BIN . ' ' . CRON_PATH . 'watch.php ' . intval(RequestManager::getAll()['id']) . ' >/dev/null 2>/dev/null &');

			break;

		case 'plex_force':
			shell_exec(PHP_BIN . ' ' . CRON_PATH . 'plex.php ' . intval(RequestManager::getAll()['id']) . ' >/dev/null 2>/dev/null &');

			break;

		case 'get_archive_files':
			$rStreamID = intval(RequestManager::getAll()['stream_id']);
			echo json_encode(array('result' => true, 'data' => glob(ARCHIVE_PATH . $rStreamID . '/*.ts')));

			exit();

		case 'kill_watch':
			if (file_exists(CACHE_TMP_PATH . 'watch_pid')) {
				$rPrevPID = intval(file_get_contents(CACHE_TMP_PATH . 'watch_pid'));
			} else {
				$rPrevPID = null;
			}

			if (!($rPrevPID && ProcessManager::isRunning($rPrevPID, 'php'))) {
			} else {
				shell_exec('kill -9 ' . $rPrevPID);
			}

			$rPIDs = glob(WATCH_TMP_PATH . '*.wpid');

			foreach ($rPIDs as $rPIDFile) {
				$rPID = intval(basename($rPIDFile, '.wpid'));

				if (!($rPID && ProcessManager::isRunning($rPID, 'php'))) {
				} else {
					shell_exec('kill -9 ' . $rPID);
				}

				unlink($rPIDFile);
			}

			exit(json_encode(array('result' => true)));

		case 'kill_plex':
			if (file_exists(CACHE_TMP_PATH . 'plex_pid')) {
				$rPrevPID = intval(file_get_contents(CACHE_TMP_PATH . 'plex_pid'));
			} else {
				$rPrevPID = null;
			}

			if (!($rPrevPID && ProcessManager::isRunning($rPrevPID, 'php'))) {
			} else {
				shell_exec('kill -9 ' . $rPrevPID);
			}

			$rPIDs = glob(WATCH_TMP_PATH . '*.ppid');

			foreach ($rPIDs as $rPIDFile) {
				$rPID = intval(basename($rPIDFile, '.ppid'));

				if (!($rPID && ProcessManager::isRunning($rPID, 'php'))) {
				} else {
					shell_exec('kill -9 ' . $rPID);
				}

				unlink($rPIDFile);
			}

			exit(json_encode(array('result' => true)));

		case 'probe':
			if (empty(RequestManager::getAll()['url'])) {
				exit(json_encode(array('result' => false)));
			}

			$rURL = RequestManager::getAll()['url'];
			$rFetchArguments = array();

			if (!RequestManager::getAll()['user_agent']) {
			} else {
				$rFetchArguments[] = sprintf("-user_agent '%s'", escapeshellcmd(RequestManager::getAll()['user_agent']));
			}

			if (!RequestManager::getAll()['http_proxy']) {
			} else {
				$rFetchArguments[] = sprintf("-http_proxy '%s'", escapeshellcmd(RequestManager::getAll()['http_proxy']));
			}

			if (!RequestManager::getAll()['cookies']) {
			} else {
				$rFetchArguments[] = sprintf("-cookies '%s'", escapeshellcmd(RequestManager::getAll()['cookies']));
			}

			$rHeaders = (RequestManager::getAll()['headers'] ? rtrim(RequestManager::getAll()['headers'], "\r\n") . "\r\n" : '');
			$rHeaders .= 'X-XC_VM-Prebuffer:1' . "\r\n";
			$rFetchArguments[] = sprintf('-headers %s', escapeshellarg($rHeaders));

			exit(json_encode(array('result' => true, 'data' => FFprobeRunner::probeStream($rURL, $rFetchArguments, '', false))));



		default:
			exit(json_encode(array('result' => false)));
	}
}

function shutdown() {
	global $db;
	global $rDeny;

	if ($rDeny) {
		BruteforceGuard::checkFlood();
	}

	if (is_object($db)) {
		$db->close_mysql();
	}
}
