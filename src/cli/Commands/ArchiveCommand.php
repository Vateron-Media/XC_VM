<?php

/**
 * ArchiveCommand — archive command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ArchiveCommand implements CommandInterface {

	public function getName(): string {
		return 'archive';
	}

	public function getDescription(): string {
		return 'TV Archive — record stream into segments';
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

		register_shutdown_function(function () {
			global $db;
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		$this->checkRunning($rStreamID);
		set_time_limit(0);
		cli_set_process_title('TVArchive[' . $rStreamID . ']');

		global $db;

		if (!file_exists(ARCHIVE_PATH . $rStreamID)) {
			mkdir(ARCHIVE_PATH . $rStreamID);
		}
		$rPID = (file_exists(STREAMS_PATH . $rStreamID . '_.pid') ? intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid')) : 0);
		$rPlaylist = STREAMS_PATH . $rStreamID . '_.m3u8';
		if (0 >= $rPID) {
			return 0;
		}
		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t1.id = t2.stream_id AND t2.server_id = t1.tv_archive_server_id WHERE t1.`id` = ? AND t1.`tv_archive_server_id` = ? AND t1.`tv_archive_duration` > 0', $rStreamID, SERVER_ID);
		if (0 >= $db->num_rows()) {
			return 0;
		}
		$rRow = $db->get_row();
		if (ProcessManager::isRunning($rRow['tv_archive_pid'], PHP_BIN)) {
			if (is_numeric($rRow['tv_archive_pid']) && 0 < $rRow['tv_archive_pid']) {
				posix_kill($rRow['tv_archive_pid'], 9);
			}
		}
		if (empty($rRow['pid'])) {
			posix_kill(getmypid(), 9);
		}
		$db->query('UPDATE `streams` SET `tv_archive_pid` = ? WHERE `id` = ?', getmypid(), $rStreamID);
		StreamProcess::updateStream($rStreamID);
		$db->close_mysql();
		while (ProcessManager::isStreamRunning($rPID, $rStreamID) && file_exists($rPlaylist)) {
			$rLastCheck = time();
			$this->deleteSegments($rStreamID, $rRow['tv_archive_duration']);
			$rFileTime = gmdate('Y-m-d:H-i');
			$rFP = @fopen('http://127.0.0.1:' . ServerRepository::getAll()[SERVER_ID]['http_broadcast_port'] . '/admin/live?password=' . SettingsManager::getAll()['live_streaming_pass'] . '&stream=' . $rStreamID . '&extension=ts', 'r');
			if ($rFP) {
				$rWriteFile = fopen(ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts', 'a');
				while (!feof($rFP)) {
					if (3600 <= time() - $rLastCheck) {
						$this->deleteSegments($rStreamID, $rRow['tv_archive_duration']);
						$rLastCheck = time();
					}
					if (gmdate('Y-m-d:H-i') != $rFileTime) {
						fclose($rWriteFile);
						if (!file_exists(ARCHIVE_PATH . $rStreamID)) {
							mkdir(ARCHIVE_PATH . $rStreamID);
						}
						$rOffset = (StreamUtils::findKeyframe(ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts') ?: 0);
						file_put_contents(ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts.offset', $rOffset);
						$rFileTime = gmdate('Y-m-d:H-i');
						$rWriteFile = fopen(ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts', 'a');
					}
					fwrite($rWriteFile, stream_get_line($rFP, 4096));
					fflush($rWriteFile);
				}
				fclose($rFP);
			}
			sleep(1);
		}

		return 0;
	}

	private function deleteSegments($rStreamID, $rDuration): void {
		$rSegmentCount = intval(count(scandir(ARCHIVE_PATH . $rStreamID . '/')) - 2);
		if ($rDuration * 24 * 60 < $rSegmentCount) {
			$rDelta = $rSegmentCount - $rDuration * 24 * 60;
			$rFiles = array_values(array_filter(explode("\n", shell_exec('ls -tr ' . ARCHIVE_PATH . intval($rStreamID) . " | sed -e 's/\\s\\+/\\n/g'"))));
			for ($i = 0; $i < $rDelta; $i++) {
				unlink(ARCHIVE_PATH . $rStreamID . '/' . $rFiles[$i]);
			}
		}
	}

	private function checkRunning($rStreamID): void {
		clearstatcache(true);
		$rPID = null;
		if (file_exists(STREAMS_PATH . $rStreamID . '_.archive')) {
			$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.archive'));
		}
		if (empty($rPID)) {
			shell_exec("kill -9 `ps -ef | grep 'TVArchive\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
		} else {
			if (file_exists('/proc/' . $rPID)) {
				$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
				if ($rCommand == 'TVArchive[' . $rStreamID . ']' && is_numeric($rPID) && 0 < $rPID) {
					posix_kill($rPID, 9);
				}
			}
		}
		file_put_contents(STREAMS_PATH . $rStreamID . '_.archive', getmypid());
	}
}
