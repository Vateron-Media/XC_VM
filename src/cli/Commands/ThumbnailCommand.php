<?php

/**
 * ThumbnailCommand — thumbnail command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ThumbnailCommand implements CommandInterface {

	public function getName(): string {
		return 'thumbnail';
	}

	public function getDescription(): string {
		return 'Generate thumbnail frames for stream';
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
		cli_set_process_title('Thumbnail[' . $rStreamID . ']');

		global $db;

		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t1.id = t2.stream_id AND t2.server_id = t1.vframes_server_id WHERE t1.`id` = ? AND t1.`vframes_server_id` = ?', $rStreamID, SERVER_ID);
		if (0 < $db->num_rows()) {
			$rRow = $db->get_row();
			$db->query('UPDATE `streams` SET `vframes_pid` = ? WHERE `id` = ?', getmypid(), $rStreamID);
			StreamProcess::updateStream($rStreamID);
			$db->close_mysql();
			while (ProcessManager::isStreamRunning($rRow['pid'], $rStreamID)) {
				shell_exec(FfmpegPaths::cpu() . ' -y -i "' . STREAMS_PATH . $rStreamID . '_.m3u8" -qscale:v 4 -frames:v 1 "' . STREAMS_PATH . $rStreamID . '_.jpg" >/dev/null 2>/dev/null &');
				sleep(5);
			}
		}

		return 0;
	}

	private function checkRunning($rStreamID): void {
		clearstatcache(true);
		$rPID = null;
		if (file_exists(STREAMS_PATH . $rStreamID . '_.thumb')) {
			$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.thumb'));
		}
		if (empty($rPID)) {
			shell_exec("kill -9 `ps -ef | grep 'Thumbnail\\[" . $rStreamID . "\\]' | grep -v grep | awk '{print \$2}'`;");
		} else {
			if (file_exists('/proc/' . $rPID)) {
				$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
				if ($rCommand == 'Thumbnail[' . $rStreamID . ']' && is_numeric($rPID) && 0 < $rPID) {
					posix_kill($rPID, 9);
				}
			}
		}
		file_put_contents(STREAMS_PATH . $rStreamID . '_.thumb', getmypid());
	}
}
