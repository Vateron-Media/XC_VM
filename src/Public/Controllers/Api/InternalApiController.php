<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Redactor;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Core\Util\StreamUtils;
use XcVm\Core\Util\SystemInfo;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\Stream\StreamControlDispatcher;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Streaming\Codec\FFprobeRunner;
use XcVm\Streaming\Fanout\FanoutClient;

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
	/**
	 * Run one system action for a signed `node.rpc` command (cluster:exec on
	 * the node, Phase 4): the same handlers as the legacy /api, without its
	 * password and source-IP checks, which the command's panel signature
	 * replaces. Output is the action's response, as /api would send it.
	 *
	 * @param array<string, mixed> $rRequest action + arguments
	 */
	public function runCommand(array $rRequest): void {
		$this->dispatch((string) ($rRequest['action'] ?? ''), $rRequest, SettingsManager::getAll());
	}

	/** Remote acknowledgements describe each actual control, not unconditional success. */
	private function controls(string $rAction, array $rRequest): never {
		$rAccepted = $rErrors = [];
		$rFunction = (string) ($rRequest['function'] ?? '');
		foreach ((array) ($rRequest['stream_ids'] ?? []) as $rID) {
			$rID = (int) $rID;
			try {
				$rOK = StreamControlDispatcher::local($rAction, $rFunction, $rID, !empty($rRequest['force']));
			} catch (\Throwable) {
				$rOK = false;
			}
			if ($rOK) {
				$rAccepted[] = ['server_id' => (int) SERVER_ID, 'stream_id' => $rID];
			} else {
				$rErrors[] = ['server_id' => (int) SERVER_ID, 'stream_id' => $rID, 'code' => 'LOCAL_CONTROL_REFUSED'];
			}
		}
		echo json_encode(['result' => $rAccepted !== [] && $rErrors === [], 'accepted' => $rAccepted, 'errors' => $rErrors]);
		exit();
	}

	private function dispatch($rAction, $rRequest, $rSettings) {
		switch ($rAction) {
			case 'fpm_status':
				echo file_get_contents('http://127.0.0.1:' . ServerRepository::getAll()[SERVER_ID]['http_broadcast_port'] . '/status');

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
				$rReturn = ['result' => true, 'streams' => []];
				exec('ls -l ' . STREAMS_PATH, $rFiles);

				foreach ($rFiles as $rFile) {
					$rSplit = explode(' ', preg_replace('!\\s+!', ' ', $rFile));

					// The "total N" header line (and any other malformed `ls -l`
					// row) has fewer columns than a real entry — skip it instead
					// of reading past the end of $rSplit.
					if (count($rSplit) < 5) {
						continue;
					}

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
				$this->controls('vod', $rRequest);

			case 'rtmp_stats':
				echo json_encode(ServerRepository::getLocalRTMPStats());

				break;

			case 'kill_pid':
				$rPID = intval($rRequest['pid']);

				if (0 < $rPID) {
					posix_kill($rPID, 9);
					echo json_encode(['result' => true]);
				} else {
					echo json_encode(['result' => false]);
				}

				break;

			case 'rtmp_kill':
				$rName = $rRequest['name'];
				shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . ServerRepository::getAll()[SERVER_ID]['rtmp_mport_url'] . 'control/drop/publisher?app=live&name=' . escapeshellcmd($rName) . '" >/dev/null 2>/dev/null &');
				echo json_encode(['result' => true]);

				exit();

			case 'stream':
				$this->controls('stream', $rRequest);

			case 'stats':
				echo json_encode(SystemInfo::getStats());

				exit();

			case 'force_stream':
				$rStreamID = intval($rRequest['stream_id']);
				$rForceID = intval($rRequest['force_id']);

				if ($rStreamID > 0) {
					// A supervised stream switches through the daemon. The .force file
					// is only ever read by the PHP monitor, which a supervised stream
					// does not have, so writing it there would silently do nothing.
					if (!FanoutClient::forceSource($rStreamID, $rForceID)) {
						file_put_contents(SIGNALS_TMP_PATH . $rStreamID . '.force', $rForceID);
					}
				}

				exit(json_encode(['result' => true]));

			case 'closeConnection':
				ConnectionTracker::closeConnection(ConnectionTracker::reference($rRequest['activity_id'] ?? ''));

				exit(json_encode(['result' => true]));

			case 'scandir_recursive':
				set_time_limit(30);
				$rDirectory = urldecode($rRequest['dir']);
				$rAllowed = !empty($rRequest['allowed']) ? urldecode($rRequest['allowed']) : null;
				// `stat`: each file with its modification time, so MAIN's watch scan skips a
				// file still being written here as it does in its own folders; a page at a time
				// from `after` (findPage()).
				$rStat = !empty($rRequest['stat']);

				// `size` with `stat`, for one file: its size too, and an empty answer for
				// a file that is gone from a folder this node lists (MAIN's auto-upgrade
				// compares two copies here, where they are: ApiClient::fileSizes()).
				// The file itself must be under a Scan Root (a link out of one is not); one
				// that is gone is answered for when its folder is.
				if ($rStat && !empty($rRequest['size']) && !is_dir($rDirectory) && ClusterSettings::pathAllowed(file_exists($rDirectory) ? $rDirectory : dirname($rDirectory), $rSettings['lb_scan_roots'] ?? null)) {
					exit(json_encode(self::fileStat($rDirectory), JSON_UNESCAPED_UNICODE));
				}

				if (!file_exists($rDirectory) || !ClusterSettings::pathAllowed($rDirectory, $rSettings['lb_scan_roots'] ?? null)) {
					exit(json_encode(['result' => false]));
				}

				$rCommand = self::findCommand($rDirectory, $rAllowed, $rStat);
				if ($rCommand === null) {
					exit(json_encode(['result' => false]));
				}
				// The directory is escapeshellarg()'d and inside a Scan Root, the extensions are letters and digits (findCommand() refuses anything else), the rest is constant.
				// nosemgrep: php.lang.security.exec-use.exec-use
				exec($rCommand, $rReturn);
				if ($rStat) {
					echo json_encode(self::findPage(self::findTimes($rReturn), isset($rRequest['after']) ? urldecode((string) $rRequest['after']) : null), JSON_UNESCAPED_UNICODE);
				} else {
					echo json_encode($rReturn, JSON_UNESCAPED_UNICODE);
				}

				exit();

			case 'scandir':
				set_time_limit(30);
				$rDirectory = urldecode($rRequest['dir']);
				$rAllowed = !empty($rRequest['allowed']) ? explode('|', urldecode($rRequest['allowed'])) : [];

				if (!file_exists($rDirectory) || !ClusterSettings::pathAllowed($rDirectory, $rSettings['lb_scan_roots'] ?? null)) {
					exit(json_encode(['result' => false]));
				}

				$rReturn = ['result' => true, 'dirs' => [], 'files' => []];
				$rFiles = scandir($rDirectory);

				foreach ($rFiles as $rValue) {
					if (in_array($rValue, ['.', '..'])) {
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
				// Full argv: stream sources and viewer tokens carry credentials, and
				// this answer is stored in cluster_commands.result and shown in admin.
				echo json_encode(array_map([Redactor::class, 'redact'], $rReturn));

				exit();

			case 'free_temp':
				exec('rm -rf ' . MAIN_HOME . 'tmp/*');
				shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache');
				echo json_encode(['result' => true]);

				break;

			case 'free_streams':
				exec('rm ' . MAIN_HOME . 'content/streams/*');
				echo json_encode(['result' => true]);

				break;

			case 'signal_send':
				if (!empty($rRequest['message']) && !empty($rRequest['uuid'])) {
					RequestManager::update('type', 'signal');
					// With fanout on, push the "send message" overlay to the xc_fanout
					// daemon — it burns the banner onto the viewer's next HLS segment /
					// a short live-TS window. With fanout off (FanoutMode) that call is
					// a no-op, and the signal file below is what the pre-fanout path
					// reads (live.php's TS feed, segment.php).
					FanoutClient::sendSignal($rRequest['uuid'], $rRequest);
					file_put_contents(SIGNALS_PATH . $rRequest['uuid'], json_encode($rRequest));
				}

				break;

			case 'get_certificate_info':
				echo json_encode(DiagnosticsService::getCertificateInfo());

				exit();

			case 'get_archive_files':
				$rStreamID = intval($rRequest['stream_id']);
				echo json_encode(['result' => true, 'data' => glob(ARCHIVE_PATH . $rStreamID . '/*.ts')]);

				exit();

			case 'kill_plex':
				$this->killProcessGroup(CACHE_TMP_PATH . 'plex_pid', WATCH_TMP_PATH . '*.ppid');

				exit(json_encode(['result' => true]));

			case 'probe':
				$this->probeStream($rRequest);

				break;

			default:
				exit(json_encode(['result' => false]));
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

	/**
	 * `scandir_recursive`'s find: every path under $rDirectory, those whose
	 * extension is in $rAllowed (a|b|c), and with $rStat files only, each
	 * line "<mtime>\t<path>" (findTimes()). Null when $rAllowed is not a list
	 * of extensions (letters and digits): it is put inside find's -regex, where
	 * escapeshellcmd() alone leaves paired quotes, so another value could add
	 * arguments to find (its unclosable `\(` makes find refuse such a line, but
	 * nothing but extensions is ever meant to be there).
	 */
	private static function findCommand(string $rDirectory, ?string $rAllowed, bool $rStat): ?string {
		if ($rAllowed !== null && !preg_match('/^[A-Za-z0-9]{1,16}(\|[A-Za-z0-9]{1,16}){0,63}$/', $rAllowed)) {
			return null;
		}
		$rCommand = '/usr/bin/find ' . escapeshellarg($rDirectory);
		if ($rAllowed) {
			$rCommand .= ' -regex ".*\\.\\(' . escapeshellcmd($rAllowed) . '\\)"';
		}
		return $rStat ? $rCommand . " -type f -printf '%T@\\t%p\\n'" : $rCommand;
	}

	/**
	 * Bytes of file entries in one `stat` page: below CommandBus::MAX_RESULT
	 * (65536), which is all of a node's answer that reaches MAIN through the
	 * command queue, with room for the rest of it.
	 */
	private const LISTING_PAGE_BYTES = 60000;

	/**
	 * One page of a `stat` listing: in path order, the files after $rAfter
	 * (null: from the first) that fit in LISTING_PAGE_BYTES, and the last path
	 * of the page as `next` (null on the last page). A cursor by path, not by
	 * position: a file added or removed between two pages makes none of the
	 * others skipped. `files` is always a JSON object, `{}` when empty.
	 *
	 * @param array<string, int> $rTimes
	 * @return array{files: object, next: string|null}
	 */
	private static function findPage(array $rTimes, ?string $rAfter): array {
		ksort($rTimes, SORT_STRING);
		$rPage = [];
		$rBytes = 0;
		$rLast = null;
		foreach ($rTimes as $rPath => $rTime) {
			$rPath = (string) $rPath;
			if ($rAfter !== null && strcmp($rPath, $rAfter) <= 0) {
				continue;
			}
			$rBytes += strlen((string) json_encode($rPath, JSON_UNESCAPED_UNICODE)) + strlen((string) $rTime) + 2;
			if ($rBytes > self::LISTING_PAGE_BYTES && $rPage !== []) {
				return ['files' => (object) $rPage, 'next' => $rLast];
			}
			$rPage[$rPath] = $rTime;
			$rLast = $rPath;
		}
		return ['files' => (object) $rPage, 'next' => null];
	}

	/**
	 * One file's `stat` answer with its size: `files` its modification time,
	 * `sizes` its bytes, both `{}` when it is not there (or is no regular file).
	 *
	 * @return array{files: object, sizes: object, next: null}
	 */
	private static function fileStat(string $rPath): array {
		clearstatcache(true, $rPath);
		if (!is_file($rPath)) {
			return ['files' => (object) [], 'sizes' => (object) [], 'next' => null];
		}
		return ['files' => (object) [$rPath => (int) filemtime($rPath)], 'sizes' => (object) [$rPath => (int) filesize($rPath)], 'next' => null];
	}

	/**
	 * findCommand()'s stat lines as path => modification time (whole seconds).
	 *
	 * @param list<string> $rLines
	 * @return array<string, int>
	 */
	private static function findTimes(array $rLines): array {
		$rTimes = [];
		foreach ($rLines as $rLine) {
			$rParts = explode("\t", $rLine, 2);
			if (count($rParts) === 2 && $rParts[1] !== '' && is_numeric($rParts[0])) {
				$rTimes[$rParts[1]] = (int) $rParts[0];
			}
		}
		return $rTimes;
	}

	private function probeStream($rRequest) {
		if (empty($rRequest['url'])) {
			exit(json_encode(['result' => false]));
		}

		$rURL = $rRequest['url'];

		if (!NetworkUtils::probeTargetAllowed($rURL)) {
			exit(json_encode(['result' => false, 'error' => 'Invalid probe target.']));
		}

		// A platform page (YouTube, ...) is resolved here, through the proxy
		// ffprobe uses: the media URL only plays from the IP that resolved it.
		$rProxy = (string) ($rRequest['http_proxy'] ?? '');
		if (StreamUtils::needsResolver($rURL)) {
			$rURL = StreamUtils::parseStreamURL($rURL, $rProxy);
		}

		$rFetchArguments = [];

		if (!empty($rRequest['user_agent'])) {
			$rFetchArguments[] = '-user_agent ' . escapeshellarg($rRequest['user_agent']);
		}

		if ($rProxy !== '') {
			$rFetchArguments[] = '-http_proxy ' . escapeshellarg(StreamUtils::proxyURL($rProxy));
		}

		// Completed as a stream start completes it: the probe sends the
		// source the cookie the stream will send.
		if (!empty($rRequest['cookies'])) {
			$rFetchArguments[] = '-cookies ' . escapeshellarg(StreamUtils::fixCookie($rRequest['cookies']));
		}

		$rHeaders = !empty($rRequest['headers']) ? rtrim($rRequest['headers'], "\r\n") . "\r\n" : '';
		$rHeaders .= 'X-XC_VM-Prebuffer:1' . "\r\n";
		$rFetchArguments[] = sprintf('-headers %s', escapeshellarg($rHeaders));

		exit(json_encode(['result' => true, 'data' => FFprobeRunner::probeStream($rURL, $rFetchArguments, '', false)]));
	}
}
