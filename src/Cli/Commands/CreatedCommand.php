<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Streaming\Codec\FFmpegCommand;
use XcVm\Streaming\Codec\FfmpegPaths;
use XcVm\Streaming\Codec\FFprobeRunner;

/**
 * CreatedCommand — created command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CreatedCommand implements CommandInterface {

	public function getName(): string {
		return 'created';
	}

	public function getDescription(): string {
		return 'Created Channel — create channel from sources';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		if (empty($rArgs[0])) {
			return 0;
		}

		register_shutdown_function(function () {
			global $db;
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		$rStreamID = intval($rArgs[0]);
		$this->checkRunning($rStreamID);
		set_time_limit(0);
		cli_set_process_title('XC_VMCreate[' . $rStreamID . ']');

		global $db;

		$db->query('SELECT * FROM `streams` t1 LEFT JOIN `profiles` t3 ON t1.transcode_profile_id = t3.profile_id WHERE t1.`id` = ?', $rStreamID);
		if ($db->num_rows() == 0) {
			echo "Channel doesn't exist.\n";
			return 1;
		}
		$rStreamInfo = $db->get_row();
		$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ? AND `parent_id` IS NULL', $rStreamID, SERVER_ID);

		if ($db->num_rows() == 0) {
			echo "Channel doesn't exist on this server.\n";
			return 1;
		}
		$rServerInfo = $db->get_row();

		$rStreamInfo['stream_source'] = json_decode($rStreamInfo['stream_source'], true);
		$rServerInfo['cchannel_rsources'] = json_decode($rServerInfo['cchannel_rsources'], true);

		if (!$rServerInfo['cchannel_rsources']) {
			$rServerInfo['cchannel_rsources'] = array();
		}

		$rSourcesLeft = array_diff($rStreamInfo['stream_source'], $rServerInfo['cchannel_rsources']);

		if (empty($rSourcesLeft) && $rStreamInfo['stream_source'] === $rServerInfo['cchannel_rsources']) {
			echo 'Nothing to build - all sources are already encoded.' . "\n";
			@unlink(CREATED_PATH . $rStreamID . '_.create');
			return 0;
		}

		$rTotal = count($rStreamInfo['stream_source']);
		$rDone = $rTotal - count($rSourcesLeft);
		echo 'Building channel ' . $rStreamID . ' (' . $rStreamInfo['stream_display_name'] . '): ' . count($rSourcesLeft) . ' of ' . $rTotal . ' source(s) left' . "\n";

		foreach ($rSourcesLeft as $rSource) {
			$rMD5 = md5($rSource);
			$rProgressFile = CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.progress';

			if (file_exists(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid')) {
				$rCurrentPID = intval(file_get_contents(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid'));

				if (ProcessManager::isRunning($rCurrentPID, FfmpegPaths::cpu())) {
					exec('kill -9 ' . $rCurrentPID);
				}
			}
			@unlink($rProgressFile);
			echo 'Processing source [' . ($rDone + 1) . '/' . $rTotal . ']: ' . $rSource . '...' . "\n";

			// Source duration (best effort, local sources only) — needed to turn
			// ffmpeg's out_time into a percentage.
			if (substr($rSource, 0, 2) == 's:') {
				$rSplit = explode(':', $rSource, 3);
				$rProbePath = (intval($rSplit[1]) == SERVER_ID ? $rSplit[2] : null);
			} else {
				$rProbePath = $rSource;
			}
			$rDuration = 0;
			if ($rProbePath && ($rProbe = FFprobeRunner::probeStream($rProbePath))) {
				$rDuration = floatval($rProbe['of_duration'] ?? 0);
			}

			$rItemPID = FFmpegCommand::createChannelItem($rStreamID, $rSource);
			$db->close_mysql();
			if ($rItemPID > 0) {
				$rLastUpdate = 0;
				while (ProcessManager::isRunning($rItemPID, FfmpegPaths::cpu())) {
					sleep(1);
					if (time() - $rLastUpdate < 10) {
						continue;
					}
					$rLastUpdate = time();

					$rEncode = $this->readEncodeProgress($rProgressFile);
					$rOutSecs = (isset($rEncode['out_time_us']) ? intval($rEncode['out_time_us']) / 1000000 : 0);
					$rPct = ($rDuration > 0 ? min(99.9, round($rOutSecs / $rDuration * 100, 1)) : null);

					echo "\t" . 'Encoding ' . gmdate('H:i:s', (int) $rOutSecs)
						. ($rDuration > 0 ? ' / ' . gmdate('H:i:s', (int) $rDuration) . ' (' . $rPct . '%)' : '')
						. (isset($rEncode['speed']) ? ' @ ' . $rEncode['speed'] : '') . "\n";

					$db->db_connect();
					$db->query('UPDATE `streams_servers` SET `progress_info` = ? WHERE `server_stream_id` = ?', json_encode(array('cc_encode' => array(
						'source'   => $rDone + 1,
						'total'    => $rTotal,
						'pct'      => $rPct,
						'out_time' => gmdate('H:i:s', (int) $rOutSecs),
						'speed'    => ($rEncode['speed'] ?? null),
					))), $rServerInfo['server_stream_id']);
					$db->close_mysql();
				}
			}
			$db->db_connect();
			@unlink(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid');
			@unlink(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.errors');
			@unlink($rProgressFile);

			$rDone++;
			echo "\t" . 'Source finished (' . $rDone . '/' . $rTotal . ')' . "\n";
			$rServerInfo['cchannel_rsources'][] = $rSource;
			$db->query('UPDATE `streams_servers` SET `cchannel_rsources` = ? WHERE `server_stream_id` = ?', json_encode($rServerInfo['cchannel_rsources']), $rServerInfo['server_stream_id']);
		}

		$db->query("UPDATE `streams_servers` SET `progress_info` = '' WHERE `server_stream_id` = ?", $rServerInfo['server_stream_id']);

		$rOutputList = '';
		foreach ($rStreamInfo['stream_source'] as $rSource) {
			if (substr($rSource, 0, 2) == 's:') {
				$rSplit = explode(':', $rSource, 3);
				$rServerID = intval($rSplit[1]);
				$rSourcePath = $rSplit[2];
			} else {
				$rServerID = SERVER_ID;
				$rSourcePath = $rSource;
			}

			if ($rServerID == SERVER_ID && intval($rStreamInfo['movie_symlink']) == 1) {
				if (file_exists($rSourcePath)) {
					$rOutputList .= "file '" . $rSourcePath . "'" . "\n";
				}
			} else {
				$rCreatedFile = CREATED_PATH . $rStreamID . '_' . md5($rSource) . '.ts';
				if (file_exists($rCreatedFile)) {
					$rOutputList .= "file '" . $rCreatedFile . "'" . "\n";
				}
			}
		}

		$rOutputList = base64_encode($rOutputList);

		shell_exec('echo ' . $rOutputList . ' | base64 --decode > "' . CREATED_PATH . intval($rStreamID) . '_.list"');

		StreamProcess::updateStream($rStreamID);

		$rInt = $rSeconds = 0;
		$rList = explode("\n", file_get_contents(CREATED_PATH . $rStreamID . '_.list'));
		$rReturn = array();

		foreach ($rList as $rItem) {
			$parts = explode("'", $rItem);
			if (!isset($parts[1])) continue;

			$rFilename = $parts[1];

			if (file_exists($rFilename)) {
				$rFileInfo = FFprobeRunner::probeStream($rFilename);
				$rReturn[] = array(
					'position' => $rInt,
					'filename' => basename($rFilename),
					'path' => $rFilename,
					'stream_info' => $rFileInfo,
					'seconds' => $rFileInfo['of_duration'],
					'start' => $rSeconds,
					'finish' => $rSeconds + $rFileInfo['of_duration']
				);

				$rSeconds += $rFileInfo['of_duration'];
				$rInt++;
			}
		}

		file_put_contents(CREATED_PATH . $rStreamID . '_.info', json_encode($rReturn, JSON_UNESCAPED_UNICODE));

		echo 'Completed!' . "\n";
		@unlink(CREATED_PATH . $rStreamID . '_.create');

		return 0;
	}

	/**
	 * Parse the tail of an ffmpeg -progress file into key => value pairs.
	 * Later blocks overwrite earlier keys, so the result reflects the
	 * most recent progress snapshot.
	 */
	private function readEncodeProgress(string $rFile): array {
		if (!is_file($rFile)) {
			return array();
		}

		$rHandle = @fopen($rFile, 'r');
		if (!$rHandle) {
			return array();
		}
		if (filesize($rFile) > 4096) {
			fseek($rHandle, -4096, SEEK_END);
		}
		$rTail = stream_get_contents($rHandle);
		fclose($rHandle);

		$rOutput = array();
		foreach (array_filter(array_map('trim', explode("\n", (string) $rTail))) as $rRow) {
			$rParts = explode('=', $rRow, 2);
			if (count($rParts) == 2) {
				$rOutput[trim($rParts[0])] = trim($rParts[1]);
			}
		}
		return $rOutput;
	}

	private function checkRunning($rStreamID): void {
		clearstatcache(true);

		$createFile = CREATED_PATH . $rStreamID . '_.create';
		$rPID = null;

		if (file_exists($createFile)) {
			$rPID = intval(file_get_contents($createFile));
		}

		if (empty($rPID)) {
			shell_exec("kill -9 `ps -ef | grep 'XC_VMCreate\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
		} else {
			if (file_exists('/proc/' . $rPID)) {
				$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
				if ($rCommand == 'XC_VMCreate[' . $rStreamID . ']') {
					posix_kill($rPID, 9);
				}
			}
		}

		file_put_contents($createFile, getmypid());
	}
}
