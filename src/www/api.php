<?php

register_shutdown_function('shutdown');
set_time_limit(0);
require 'init.php';
$rDeny = true;
loadapi();
function loadapi() {
	global $rDeny;

	// // я хз что должен вернуть ответ этого api он нигде не упоменается
	// // и у меня просто нет набора данных для эмуляции этой функции
	// if (!empty(CoreUtilities::$rRequest['status'])) {
	// 	if ($rStatus = checkStatus(CoreUtilities::$rRequest['data'])) {
	// 		exit($rStatus);
	// 	}
	// }

	if (empty(CoreUtilities::$rRequest['password']) || CoreUtilities::$rRequest['password'] != CoreUtilities::$rSettings['live_streaming_pass']) {
		generateError('INVALID_API_PASSWORD');
	}

	unset(CoreUtilities::$rRequest['password']);

	if (!in_array($rIP, CoreUtilities::getAllowedIPs())) {
		generateError('API_IP_NOT_ALLOWED');
	}

	header('Access-Control-Allow-Origin: *');
	$rAction = (!empty(CoreUtilities::$rRequest['action']) ? CoreUtilities::$rRequest['action'] : '');
	$rDeny = false;

	switch ($rAction) {
		case 'view_log':
			if (empty(CoreUtilities::$rRequest['stream_id'])) {
				break;
			}

			$rStreamID = intval(CoreUtilities::$rRequest['stream_id']);

			if (file_exists(STREAMS_PATH . $rStreamID . '.errors')) {
				echo file_get_contents(STREAMS_PATH . $rStreamID . '.errors');
			} else {
				if (file_exists(VOD_PATH . $rStreamID . '.errors')) {
					echo file_get_contents(VOD_PATH . $rStreamID . '.errors');
				}
			}

			exit();


		case 'fpm_status':
			echo file_get_contents('http://127.0.0.1:' . CoreUtilities::$rServers[SERVER_ID]['http_broadcast_port'] . '/status');

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
			if (empty(CoreUtilities::$rRequest['stream_ids']) || empty(CoreUtilities::$rRequest['function'])) {
			} else {
				$rStreamIDs = array_map('intval', CoreUtilities::$rRequest['stream_ids']);
				$rFunction = CoreUtilities::$rRequest['function'];

				switch ($rFunction) {
					case 'start':
						foreach ($rStreamIDs as $rStreamID) {
							CoreUtilities::stopMovie($rStreamID, true);

							if (isset(CoreUtilities::$rRequest['force']) && CoreUtilities::$rRequest['force']) {
								CoreUtilities::startMovie($rStreamID);
							} else {
								CoreUtilities::queueMovie($rStreamID);
							}
						}
						echo json_encode(array('result' => true));

						exit();

					case 'stop':
						foreach ($rStreamIDs as $rStreamID) {
							CoreUtilities::stopMovie($rStreamID);
						}
						echo json_encode(array('result' => true));

						exit();
				}
			}

			// no break
		case 'rtmp_stats':
			echo json_encode(CoreUtilities::getRTMPStats());

			break;

		case 'kill_pid':
			$rPID = intval(CoreUtilities::$rRequest['pid']);

			if (0 < $rPID) {
				posix_kill($rPID, 9);
				echo json_encode(array('result' => true));
			} else {
				echo json_encode(array('result' => false));
			}

			break;

		case 'rtmp_kill':
			$rName = CoreUtilities::$rRequest['name'];
			shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . CoreUtilities::$rServers[SERVER_ID]['rtmp_mport_url'] . 'control/drop/publisher?app=live&name=' . escapeshellcmd($rName) . '" >/dev/null 2>/dev/null &');
			echo json_encode(array('result' => true));

			exit();

		case 'stream':
			if (empty(CoreUtilities::$rRequest['stream_ids']) || empty(CoreUtilities::$rRequest['function'])) {
			} else {
				$rStreamIDs = array_map('intval', CoreUtilities::$rRequest['stream_ids']);
				$rFunction = CoreUtilities::$rRequest['function'];

				switch ($rFunction) {
					case 'start':
						foreach ($rStreamIDs as $rStreamID) {
							if (CoreUtilities::startMonitor($rStreamID, true)) {
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
							CoreUtilities::stopStream($rStreamID, true);
						}
						echo json_encode(array('result' => true));

						exit();

					default:
						break;
				}
			}

			// no break
		case 'stats':
			echo json_encode(CoreUtilities::getStats());

			exit();

		case 'force_stream':
			$rStreamID = intval(CoreUtilities::$rRequest['stream_id']);
			$rForceID = intval(CoreUtilities::$rRequest['force_id']);

			if (0 >= $rStreamID) {
			} else {
				file_put_contents(SIGNALS_TMP_PATH . $rStreamID . '.force', $rForceID);
			}

			exit(json_encode(array('result' => true)));

		case 'closeConnection':
			CoreUtilities::closeConnection(intval(CoreUtilities::$rRequest['activity_id']));

			exit(json_encode(array('result' => true)));

		case 'pidsAreRunning':
			if (empty(CoreUtilities::$rRequest['pids']) || !is_array(CoreUtilities::$rRequest['pids']) || empty(CoreUtilities::$rRequest['program'])) {
				break;
			}

			$rPIDs = array_map('intval', CoreUtilities::$rRequest['pids']);
			$rProgram = CoreUtilities::$rRequest['program'];
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
			if (empty(CoreUtilities::$rRequest['filename'])) {
				break;
			}

			$rFilename = CoreUtilities::$rRequest['filename'];

			if (in_array(strtolower(pathinfo($rFilename)['extension']), array('log', 'tar.gz', 'gz', 'zip', 'm3u8', 'mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts', 'srt', 'sub', 'sbv', 'jpg', 'png', 'bmp', 'jpeg', 'gif', 'tif'))) {

				if (!(file_exists($rFilename) && is_readable($rFilename))) {
				} else {
					header('Content-Type: application/octet-stream');
					$rFP = @fopen($rFilename, 'rb');
					$rSize = filesize($rFilename);
					$rLength = $rSize;
					$rStart = 0;
					$rEnd = $rSize - 1;
					header('Accept-Ranges: 0-' . $rLength);

					if (!isset($_SERVER['HTTP_RANGE'])) {
					} else {
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

					while (!feof($rFP) && ftell($rFP) <= $rEnd) {
						echo stream_get_line($rFP, (intval(CoreUtilities::$rSettings['read_buffer_size']) ?: 8192));
					}
					fclose($rFP);
				}

				exit();
			}

			exit(json_encode(array('result' => false, 'error' => 'Invalid file extension.')));

		case 'scandir_recursive':
			set_time_limit(30);
			$rDirectory = urldecode(CoreUtilities::$rRequest['dir']);
			$rAllowed = (!empty(CoreUtilities::$rRequest['allowed']) ? urldecode(CoreUtilities::$rRequest['allowed']) : null);

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
			$rDirectory = urldecode(CoreUtilities::$rRequest['dir']);
			$rAllowed = (!empty(CoreUtilities::$rRequest['allowed']) ? explode('|', urldecode(CoreUtilities::$rRequest['allowed'])) : array());

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
						$rExt = strtolower(pathinfo($rValue)['extension']);

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
			if (!empty(CoreUtilities::$rRequest['uuid']) || !empty(CoreUtilities::$rRequest['stream_id'])) {
				CoreUtilities::$rRequest['type'] = 'redirect';
				file_put_contents(SIGNALS_PATH . CoreUtilities::$rRequest['uuid'], json_encode(CoreUtilities::$rRequest));
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
			if (empty(CoreUtilities::$rRequest['message']) || empty(CoreUtilities::$rRequest['uuid'])) {
			} else {
				CoreUtilities::$rRequest['type'] = 'signal';
				file_put_contents(SIGNALS_PATH . CoreUtilities::$rRequest['uuid'], json_encode(CoreUtilities::$rRequest));
			}

			break;

		case 'get_certificate_info':
			echo json_encode(CoreUtilities::getCertificateInfo());

			exit();

		case 'watch_force':
			shell_exec(PHP_BIN . ' ' . CRON_PATH . 'watch.php ' . intval(CoreUtilities::$rRequest['id']) . ' >/dev/null 2>/dev/null &');

			break;

		case 'plex_force':
			shell_exec(PHP_BIN . ' ' . CRON_PATH . 'plex.php ' . intval(CoreUtilities::$rRequest['id']) . ' >/dev/null 2>/dev/null &');

			break;

		case 'get_archive_files':
			$rStreamID = intval(CoreUtilities::$rRequest['stream_id']);
			echo json_encode(array('result' => true, 'data' => glob(ARCHIVE_PATH . $rStreamID . '/*.ts')));

			exit();

		case 'request_update':
			if (CoreUtilities::$rRequest['type'] == 0) {
				$rFile = LOADBALANCER_UPDATE;
			} else {
				$rFile = PROXY_UPDATE;
			}

			if (!file_exists($rFile)) {
				exit(json_encode(array('result' => false)));
			}

			$rMD5 = md5_file($rFile);
			$rURL = 'http://' . CoreUtilities::$rServers[SERVER_ID]['server_ip'] . ':' . CoreUtilities::$rServers[SERVER_ID]['http_broadcast_port'] . '/api?password=' . CoreUtilities::$rSettings['live_streaming_pass'] . '&action=getFile&filename=' . urlencode($rFile);

			exit(json_encode(array('result' => true, 'md5' => $rMD5, 'url' => $rURL, 'version' => CoreUtilities::$rServers[SERVER_ID]['xc_vm_version'])));



		case 'kill_watch':
			if (file_exists(CACHE_TMP_PATH . 'watch_pid')) {
				$rPrevPID = intval(file_get_contents(CACHE_TMP_PATH . 'watch_pid'));
			} else {
				$rPrevPID = null;
			}

			if (!($rPrevPID && CoreUtilities::isProcessRunning($rPrevPID, 'php'))) {
			} else {
				shell_exec('kill -9 ' . $rPrevPID);
			}

			$rPIDs = glob(WATCH_TMP_PATH . '*.wpid');

			foreach ($rPIDs as $rPIDFile) {
				$rPID = intval(basename($rPIDFile, '.wpid'));

				if (!($rPID && CoreUtilities::isProcessRunning($rPID, 'php'))) {
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

			if (!($rPrevPID && CoreUtilities::isProcessRunning($rPrevPID, 'php'))) {
			} else {
				shell_exec('kill -9 ' . $rPrevPID);
			}

			$rPIDs = glob(WATCH_TMP_PATH . '*.ppid');

			foreach ($rPIDs as $rPIDFile) {
				$rPID = intval(basename($rPIDFile, '.ppid'));

				if (!($rPID && CoreUtilities::isProcessRunning($rPID, 'php'))) {
				} else {
					shell_exec('kill -9 ' . $rPID);
				}

				unlink($rPIDFile);
			}

			exit(json_encode(array('result' => true)));

		case 'probe':
			if (empty(CoreUtilities::$rRequest['url'])) {
				exit(json_encode(array('result' => false)));
			}

			$rURL = escapeshellcmd(CoreUtilities::$rRequest['url']);
			$rFetchArguments = array();

			if (!CoreUtilities::$rRequest['user_agent']) {
			} else {
				$rFetchArguments[] = sprintf("-user_agent '%s'", escapeshellcmd(CoreUtilities::$rRequest['user_agent']));
			}

			if (!CoreUtilities::$rRequest['http_proxy']) {
			} else {
				$rFetchArguments[] = sprintf("-http_proxy '%s'", escapeshellcmd(CoreUtilities::$rRequest['http_proxy']));
			}

			if (!CoreUtilities::$rRequest['cookies']) {
			} else {
				$rFetchArguments[] = sprintf("-cookies '%s'", escapeshellcmd(CoreUtilities::$rRequest['cookies']));
			}

			$rHeaders = (CoreUtilities::$rRequest['headers'] ? rtrim(CoreUtilities::$rRequest['headers'], "\r\n") . "\r\n" : '');
			$rHeaders .= 'X-XC_VM-Prebuffer:1' . "\r\n";
			$rFetchArguments[] = sprintf('-headers %s', escapeshellarg($rHeaders));

			exit(json_encode(array('result' => true, 'data' => CoreUtilities::probeStream($rURL, $rFetchArguments, '', false))));



		default:
			exit(json_encode(array('result' => false)));
	}
}

function shutdown() {
	global $db;
	global $rDeny;

	if ($rDeny) {
		CoreUtilities::checkFlood();
	}

	if (is_object($db)) {
		$db->close_mysql();
	}
}
