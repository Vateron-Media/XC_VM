<?php

class DelayCommand implements CommandInterface {

	public function getName(): string {
		return 'delay';
	}

	public function getDescription(): string {
		return 'Stream Delay — delay HLS stream';
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
		$rDelayDuration = 0;

		register_shutdown_function(function () {
			global $db;
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		global $db;

		$this->checkRunning($rStreamID);
		set_time_limit(0);
		cli_set_process_title('XC_VMDelay[' . $rStreamID . ']');

		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.server_id = ? WHERE t1.id = ?', SERVER_ID, $rStreamID);
		if ($db->num_rows() <= 0) {
			return 0;
		}
		$rStreamInfo = $db->get_row();
		if ($rStreamInfo['delay_minutes'] == 0 || $rStreamInfo['parent_id']) {
			return 0;
		}

		$rPID = (file_exists(STREAMS_PATH . $rStreamID . '_.pid') ? intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid')) : $rStreamInfo['pid']);
		$rPlaylist = STREAMS_PATH . $rStreamID . '_.m3u8';
		$rPlaylistDelay = DELAY_PATH . $rStreamID . '_.m3u8';
		$rPlaylistOld = DELAY_PATH . $rStreamID . '_.m3u8_old';
		$db->query('UPDATE `streams_servers` SET delay_pid = ? WHERE stream_id = ? AND server_id = ?', getmypid(), $rStreamID, SERVER_ID);
		StreamProcess::updateStream($rStreamInfo['id']);
		$db->close_mysql();
		$rDelayDuration = intval($rStreamInfo['delay_minutes']) + 5;
		$this->cleanUpSegments($rStreamID, $rDelayDuration);
		$rSegmentSettings = array('seg_time' => intval(SettingsManager::getAll()['seg_time']), 'seg_list_size' => intval(SettingsManager::getAll()['seg_list_size']), 'seg_delete_threshold' => intval(SettingsManager::getAll()['seg_delete_threshold']));
		$rTotalSegments = intval($rSegmentSettings['seg_list_size']) + 5;
		$rOldSegments = array();
		if (file_exists($rPlaylistOld)) {
			$rOldSegments = $this->getSegments($rPlaylistOld, -1);
		}
		$rPrevMD5 = null;
		$rMD5 = md5(file_get_contents($rPlaylistDelay));
		while (ProcessManager::isStreamRunning($rPID, $rStreamID) && file_exists($rPlaylistDelay)) {
			if ($rMD5 != $rPrevMD5) {
				if (file_exists(STREAMS_PATH . $rStreamID . '_.dur')) {
					$rDuration = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.dur'));
					if ($rSegmentSettings['seg_time'] < $rDuration) {
						$rSegmentSettings['seg_time'] = $rDuration;
					}
				}
				$rM3U8 = array('vars' => array('#EXTM3U' => '', '#EXT-X-VERSION' => 3, '#EXT-X-MEDIA-SEQUENCE' => '0', '#EXT-X-TARGETDURATION' => $rSegmentSettings['seg_time']), 'segments' => $this->getData($rPlaylistDelay, $rOldSegments, $rTotalSegments, $rPlaylistOld));
				if (!empty($rM3U8['segments'])) {
					$rData = '';
					$rSequence = 0;
					if (preg_match('/.*\\_(.*?)\\.ts/', $rM3U8['segments'][0]['file'], $rMatches)) {
						$rSequence = intval($rMatches[1]);
					}
					$rM3U8['vars']['#EXT-X-MEDIA-SEQUENCE'] = $rSequence;
					foreach ($rM3U8['vars'] as $rKey => $rValue) {
						$rData .= (!empty($rValue) ? $rKey . ':' . $rValue . "\n" : $rKey . "\n");
					}
					foreach ($rM3U8['segments'] as $rSegment) {
						copy(DELAY_PATH . $rSegment['file'], STREAMS_PATH . $rSegment['file']);
						$rData .= '#EXTINF:' . $rSegment['seconds'] . ',' . "\n" . $rSegment['file'] . "\n";
					}
					file_put_contents($rPlaylist, $rData, LOCK_EX);
					$rMD5 = $rPrevMD5;
					$this->deleteSegments($rStreamID, $rSequence - 2);
					$this->cleanUpSegments($rStreamID, $rDelayDuration);
				}
			}
			usleep(1000);
			$rPrevMD5 = md5(file_get_contents($rPlaylistDelay));
		}

		return 0;
	}

	private function cleanUpSegments($rStreamID, $rDelayDuration): void {
		shell_exec('find ' . DELAY_PATH . intval($rStreamID) . '_*' . ' -type f -cmin +' . $rDelayDuration . ' -delete');
	}

	private function deleteSegments($rStreamID, $rSequence): void {
		if (file_exists(STREAMS_PATH . $rStreamID . '_' . $rSequence . '.ts')) {
			unlink(STREAMS_PATH . $rStreamID . '_' . $rSequence . '.ts');
		}
		if (file_exists(STREAMS_PATH . $rStreamID . '_' . $rSequence . '.ts.enc')) {
			unlink(STREAMS_PATH . $rStreamID . '_' . $rSequence . '.ts.enc');
		}
	}

	private function getData($rPlaylistDelay, &$rOldSegments, $rTotalSegments, $rPlaylistOld): array {
		$rSegments = array();
		if (!empty($rOldSegments)) {
			$rSegments = array_shift($rOldSegments);
			unlink(DELAY_PATH . $rSegments['file']);
			$i = 0;
			while ($i < $rTotalSegments && $i < count($rOldSegments)) {
				$rSegments[] = $rOldSegments[$i];
				$i++;
			}
			$rOldSegments = array_values($rOldSegments);
			$rSegments = array_shift($rOldSegments);
			$this->updateOldPlaylist($rOldSegments, $rPlaylistOld);
		}
		if (file_exists($rPlaylistDelay)) {
			$rSegments = array_merge($rSegments, $this->getSegments($rPlaylistDelay, $rTotalSegments - count($rSegments)));
		}
		return $rSegments;
	}

	private function updateOldPlaylist($rOldSegments, $rPlaylistOld): void {
		if (!empty($rOldSegments)) {
			$rData = '';
			foreach ($rOldSegments as $rSegment) {
				$rData .= '#EXTINF:' . $rSegment['seconds'] . ',' . "\n" . $rSegment['file'] . "\n";
			}
			file_put_contents($rPlaylistOld, $rData, LOCK_EX);
		} else {
			unlink($rPlaylistOld);
		}
	}

	private function getSegments($rPlaylist, $rCounter = 0): array {
		$rSegments = array();
		if (file_exists($rPlaylist)) {
			$rFP = fopen($rPlaylist, 'r');
			while (!feof($rFP) && count($rSegments) != $rCounter) {
				$rLine = trim(fgets($rFP));
				if (stristr($rLine, 'EXTINF')) {
					list($rVar, $rSeconds) = explode(':', $rLine);
					$rSeconds = rtrim($rSeconds, ',');
					$rSegmentFile = trim(fgets($rFP));
					if (file_exists(DELAY_PATH . $rSegmentFile)) {
						$rSegments[] = array('seconds' => $rSeconds, 'file' => $rSegmentFile);
					}
				}
			}
			fclose($rFP);
		}
		return $rSegments;
	}

	private function checkRunning($rStreamID): void {
		clearstatcache(true);
		$rPID = null;
		if (file_exists(STREAMS_PATH . $rStreamID . '_.monitor_delay')) {
			$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.monitor_delay'));
		}
		if (empty($rPID)) {
			shell_exec("kill -9 `ps -ef | grep 'XC_VMDelay\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
		} else {
			if (file_exists('/proc/' . $rPID)) {
				$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
				if ($rCommand == 'XC_VMDelay[' . $rStreamID . ']' && is_numeric($rPID) && 0 < $rPID) {
					posix_kill($rPID, 9);
				}
			}
		}
		file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor_delay', getmypid());
	}
}
