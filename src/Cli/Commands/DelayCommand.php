<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Streaming\Fanout\IngestFeeder;

/**
 * DelayCommand — delay command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

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
		$rSegmentSettings = array('seg_time' => intval(SettingsManager::get('seg_time')), 'seg_list_size' => intval(SettingsManager::get('seg_list_size')), 'seg_delete_threshold' => intval(SettingsManager::get('seg_delete_threshold')));
		$rTotalSegments = intval($rSegmentSettings['seg_list_size']) + 5;
		$rOldSegments = array();
		if (file_exists($rPlaylistOld)) {
			$rOldSegments = $this->getSegments($rPlaylistOld, -1);
		}
		// Clients are served only by the xc_fanout daemon (ADR 0003, Phase E), and a
		// delayed stream's encoder output is the undelayed one — so nothing fed the
		// daemon the delayed stream and a delayed channel could not be watched at
		// all. The segments this worker publishes are now pushed into the daemon's
		// ingest as they go out, paced over their duration so TS viewers get a
		// steady stream; the daemon re-segments them for HLS.
		$rFeeder = IngestFeeder::forStream($rStreamID, (bool) SettingsManager::get('encrypt_hls'), static function (string $rLine) use ($rStreamID) {
			@file_put_contents(STREAMS_PATH . $rStreamID . '.errors', '[Delay] ' . $rLine . "\n", FILE_APPEND | LOCK_EX);
		});
		$rFeeder->connect();
		$rFedSegment = null;
		$rFeedQueue = array();
		$rFeedCurrent = null;

		$rPrevMD5 = null;
		$rMD5 = md5((string) @file_get_contents($rPlaylistDelay));
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
					$this->queueForDaemon($rM3U8['segments'], $rFedSegment, $rFeedQueue);
					$rMD5 = $rPrevMD5;
					$this->deleteSegments($rStreamID, $rSequence - 2);
					$this->cleanUpSegments($rStreamID, $rDelayDuration);
				}
			}
			$this->pumpDaemon($rFeeder, $rFeedQueue, $rFeedCurrent);
			// 50 ms: fine enough to pace the daemon feed and to publish a new
			// delayed segment promptly (this used to spin every 1 ms, hashing the
			// playlist a thousand times a second).
			usleep(50000);
			$rPrevMD5 = md5((string) @file_get_contents($rPlaylistDelay));
		}

		$rFeeder->close(); // the daemon keeps the stream; viewers wait for the restart's feed
		return 0;
	}

	/** Segments sent at once when the worker starts, so viewers get data immediately. */
	private const FEED_SEED_SEGMENTS = 2;

	/** Queued segments past which the feed stops pacing and catches up. */
	private const FEED_BACKLOG_SEGMENTS = 3;

	/**
	 * Queue the segments just published that the daemon has not been fed yet (all
	 * but the newest FEED_SEED_SEGMENTS are skipped on the worker's first pass).
	 *
	 * @param array    $rSegments  Published segments, oldest first ({seconds, file}).
	 * @param int|null $rFedSegment Highest segment number queued so far.
	 * @param array    $rQueue     Pending {data, dur, burst} entries.
	 * @return void
	 */
	private function queueForDaemon(array $rSegments, ?int &$rFedSegment, array &$rQueue): void {
		$rNew = array();
		foreach ($rSegments as $rSegment) {
			if (preg_match('/_(\d+)\.ts$/', (string) ($rSegment['file'] ?? ''), $rMatch)) {
				$rNumber = intval($rMatch[1]);
				if ($rFedSegment === null || $rNumber > $rFedSegment) {
					$rNew[$rNumber] = $rSegment;
				}
			}
		}
		if (count($rNew) === 0) {
			return;
		}
		ksort($rNew);
		$rSeed = ($rFedSegment === null);
		if ($rSeed) {
			$rNew = array_slice($rNew, -self::FEED_SEED_SEGMENTS, null, true);
		}
		foreach ($rNew as $rNumber => $rSegment) {
			$rData = @file_get_contents(STREAMS_PATH . $rSegment['file']);
			if (!is_string($rData) || $rData === '') {
				$rData = @file_get_contents(DELAY_PATH . $rSegment['file']);
			}
			if (is_string($rData) && strlen($rData) >= 188) {
				$rData = substr($rData, 0, strlen($rData) - strlen($rData) % 188); // whole packets
				$rQueue[] = array('data' => $rData, 'dur' => max(0.5, floatval($rSegment['seconds'])), 'burst' => $rSeed);
			}
			$rFedSegment = $rNumber;
		}
	}

	/**
	 * Feed the daemon: the current segment is released in whole packets spread
	 * over 90% of its duration (so the feed never falls behind the playlist); the
	 * start-up seed, or a backlog of queued segments, is sent at once.
	 *
	 * @param IngestFeeder $rFeeder  The daemon feed.
	 * @param array        $rQueue   Pending {data, dur, burst} entries.
	 * @param array|null   $rCurrent The segment being paced ({data, sent, start, dur, burst}).
	 * @return void
	 */
	private function pumpDaemon(IngestFeeder $rFeeder, array &$rQueue, ?array &$rCurrent): void {
		$rNow = microtime(true);
		if ($rCurrent === null && count($rQueue) > 0) {
			$rItem = array_shift($rQueue);
			$rCurrent = array('data' => $rItem['data'], 'sent' => 0, 'start' => $rNow, 'dur' => $rItem['dur'], 'burst' => $rItem['burst']);
		}
		if ($rCurrent === null) {
			$rFeeder->flush();
			return;
		}

		$rLength = strlen($rCurrent['data']);
		if ($rCurrent['burst'] || count($rQueue) >= self::FEED_BACKLOG_SEGMENTS) {
			$rTarget = $rLength;
		} else {
			$rTarget = (int) min($rLength, ceil($rLength * ($rNow - $rCurrent['start']) / ($rCurrent['dur'] * 0.9)));
			$rTarget -= $rTarget % 188;
		}
		$rChunk = $rTarget - $rCurrent['sent'];
		if ($rChunk > 0) {
			$rFeeder->write(substr($rCurrent['data'], $rCurrent['sent'], $rChunk));
			$rCurrent['sent'] += $rChunk;
		} else {
			$rFeeder->flush();
		}
		if ($rCurrent['sent'] >= $rLength) {
			$rCurrent = null;
		}
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
				if ($rCommand == 'XC_VMDelay[' . $rStreamID . ']' && 0 < $rPID) {
					posix_kill($rPID, 9);
				}
			}
		}
		file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor_delay', getmypid());
	}
}
