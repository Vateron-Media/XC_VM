<?php

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
			return 0;
		}

		foreach ($rSourcesLeft as $rSource) {
			$rMD5 = md5($rSource);

			if (file_exists(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid')) {
				$rCurrentPID = intval(file_get_contents(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid'));

				if (ProcessChecker::isPIDRunning(SERVER_ID, $rCurrentPID, FfmpegPaths::cpu())) {
					exec('kill -9 ' . $rCurrentPID);
				}
			}
			echo 'Processing source: ' . $rSource . '...' . "\n";

			$rItemPID = FFmpegCommand::createChannelItem($rStreamID, $rSource);
			$db->close_mysql();
			if ($rItemPID > 0) {
				while (ProcessChecker::isPIDRunning(SERVER_ID, $rItemPID, FfmpegPaths::cpu())) {
					sleep(1);
				}
			}
			$db->db_connect();
			@unlink(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid');
			@unlink(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.errors');

			$rServerInfo['cchannel_rsources'][] = $rSource;
			$db->query('UPDATE `streams_servers` SET `cchannel_rsources` = ? WHERE `server_stream_id` = ?', json_encode($rServerInfo['cchannel_rsources']), $rServerInfo['server_stream_id']);
		}

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
		@unlink(CREATED_PATH . $rStreamID . '_.create', getmypid());

		return 0;
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
