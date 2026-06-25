<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Util\StreamUtils;
use XcVm\Domain\Stream\StreamProcess;

/**
 * ArchiveCommand — records a live HLS stream into 1-minute .ts archive segments.
 *
 * Each archive minute is stored as ARCHIVE_PATH/{id}/YYYY-MM-DD:HH-mm.ts.
 * A companion .offset file holds the byte position of the first keyframe,
 * used by the timeshift player to seek into the segment.
 *
 * Segment-complete detection: ffmpeg writes {id}_{N}.ts; the file is considered
 * fully written only when {id}_{N+1}.ts exists on disk.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/\XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ArchiveCommand implements CommandInterface {

	/** @inheritDoc */
	public function getName(): string {
		return 'archive';
	}

	/** @inheritDoc */
	public function getDescription(): string {
		return 'TV Archive — record stream into segments';
	}

	/**
	 * Entry point for the archive command.
	 *
	 * Validates preconditions (user, stream ID, DB row, archive flag),
	 * kills any previous archive worker for the same stream, then enters
	 * the main read loop until the source stream stops.
	 *
	 * @param array $rArgs CLI arguments: $rArgs[0] = stream ID (integer).
	 * @return int Exit code: 0 = clean stop, 1 = permission error.
	 */
	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as \XC_VM!\n";
			return 1;
		}

		if (empty($rArgs[0])) {
			$this->logStatus('Stream ID is missing, nothing to do.');
			return 0;
		}

		$rStreamID = intval($rArgs[0]);
		$this->logStatus('Starting archive command for stream #' . $rStreamID . '.');

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
			$this->logStatus('Created archive directory: ' . ARCHIVE_PATH . $rStreamID);
		}
		$rPID = (file_exists(STREAMS_PATH . $rStreamID . '_.pid') ? intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid')) : 0);
		$rPlaylist = STREAMS_PATH . $rStreamID . '_.m3u8';
		if (0 >= $rPID) {
			$this->logStatus('Source stream PID is not available, stop archive command.');
			return 0;
		}
		$this->logStatus('Detected source stream PID: ' . $rPID);
		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t1.id = t2.stream_id AND t2.server_id = t1.tv_archive_server_id WHERE t1.`id` = ? AND t1.`tv_archive_server_id` = ? AND t1.`tv_archive_duration` > 0', $rStreamID, SERVER_ID);
		if (0 >= $db->num_rows()) {
			$this->logStatus('Archive is disabled for this stream on current server, exiting.');
			return 0;
		}
		$rRow = $db->get_row();
		$this->logStatus('Archive duration is set to ' . $rRow['tv_archive_duration'] . ' day(s).');
		if (ProcessManager::isRunning($rRow['tv_archive_pid'], PHP_BIN)) {
			if (is_numeric($rRow['tv_archive_pid']) && 0 < $rRow['tv_archive_pid']) {
				$this->logStatus('Killing previous archive worker PID: ' . $rRow['tv_archive_pid']);
				posix_kill($rRow['tv_archive_pid'], 9);
			}
		}
		if (empty($rRow['pid'])) {
			$this->logStatus('Main stream PID is empty in DB, terminating current archive worker.');
			posix_kill(getmypid(), 9);
		}
		$db->query('UPDATE `streams` SET `tv_archive_pid` = ? WHERE `id` = ?', getmypid(), $rStreamID);
		StreamProcess::updateStream($rStreamID);
		$this->logStatus('Registered archive PID in database: ' . getmypid());
		$db->close_mysql();
		$this->logStatus('Entering archive loop.');

		// Determine which segment to start from: one past the last segment
		// currently listed in the playlist to avoid reading a partial file.
		$rPlaylistContent = @file_get_contents($rPlaylist);
		preg_match_all('/_(\d+)\.ts/', ($rPlaylistContent ?: ''), $rSegMatches);
		$rSegNum = !empty($rSegMatches[1]) ? (int)end($rSegMatches[1]) + 1 : 0;
		$this->logStatus('Starting from source segment #' . $rSegNum . '.');

		// Open the first archive minute file; subsequent ones are opened on rotation.
		$rFileTime = gmdate('Y-m-d:H-i');
		$rWriteFile = fopen(ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts', 'a');
		$rLastCheck = time();        // Timestamp of the last hourly cleanup check.
		$rBytesWritten = 0;          // Bytes written to the current archive minute file.
		$rLoopIters = 0;             // Number of fread() calls for the current minute.
		$rWaitCount = 0;             // Iterations spent waiting for segment N+1 to appear.
		$rLastProgressTime = time(); // Last time a complete segment was successfully read.
		$rLastHeartbeat = time();    // Last time a heartbeat line was emitted.
		$rTotalBytesWritten = 0;     // Total bytes written across all archive minute files.

		while (true) {
			$rStreamRunning = ProcessManager::isStreamRunning($rPID, $rStreamID);
			clearstatcache(true, $rPlaylist);
			$rPlaylistExists = file_exists($rPlaylist);
			if (!$rStreamRunning || !$rPlaylistExists) {
				$this->logStatus('Loop exit reason: stream_running=' . ($rStreamRunning ? 'yes' : 'no') . ' playlist_exists=' . ($rPlaylistExists ? 'yes' : 'no') . ' pid=' . $rPID);
				break;
			}
			// Emit a heartbeat log line every 30 s so the process is visibly alive.
			if (30 <= time() - $rLastHeartbeat) {
				$rCurrentFile = ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts';
				$rCurrentSize = file_exists($rCurrentFile) ? filesize($rCurrentFile) : 0;
				$this->logStatus('Heartbeat: current_file=' . $rFileTime . '.ts size=' . number_format($rCurrentSize / 1024, 1) . 'KB total_written=' . number_format($rTotalBytesWritten / 1024, 1) . 'KB seg=#' . $rSegNum . ' pid=' . $rPID);
				$rLastHeartbeat = time();
			}

			// Run the old-segment cleanup check once per hour.
			if (3600 <= time() - $rLastCheck) {
				$this->logStatus('Hourly segment cleanup check.');
				$this->deleteSegments($rStreamID, $rRow['tv_archive_duration']);
				$rLastCheck = time();
			}

			$rSegFile = STREAMS_PATH . $rStreamID . '_' . $rSegNum . '.ts';
			$rNextSegFile = STREAMS_PATH . $rStreamID . '_' . ($rSegNum + 1) . '.ts';

			clearstatcache(true, $rSegFile);
			clearstatcache(true, $rNextSegFile);
			$rSegExists = file_exists($rSegFile);
			$rNextExists = file_exists($rNextSegFile);

			// Wait until both segment N and N+1 exist on disk. Presence of N+1
			// guarantees that ffmpeg has finished writing N.
			if (!$rSegExists || !$rNextExists) {
				$rWaitCount++;
				// Check the playlist every ~3 s (300 iterations × 10 ms) to detect
				// segment counter gaps caused by stream restarts or ffmpeg jumps.
				if ($rWaitCount % 300 === 0) {
					$rFreshContent = @file_get_contents($rPlaylist);
					preg_match_all('/_(\d+)\.ts/', ($rFreshContent ?: ''), $rFreshMatches);
					$rFreshSeg = !empty($rFreshMatches[1]) ? (int)end($rFreshMatches[1]) : -1;

					if ($rFreshSeg >= 0 && $rFreshSeg > $rSegNum + 1) {
						// Forward gap: ffmpeg skipped segment numbers (old files purged
						// or a gap in the source). Jump ahead to avoid waiting forever.
						$this->logStatus('Gap forward: jumping from #' . $rSegNum . ' to #' . $rFreshSeg . '.');
						$rSegNum = $rFreshSeg;
						$rWaitCount = 0;
						$rLastProgressTime = time();
					} elseif ($rFreshSeg >= 0 && $rFreshSeg < $rSegNum - 1) {
						// Backward gap: ffmpeg restarted and reset its counter to a lower
						// value. Follow the new counter to avoid an infinite stall.
						$this->logStatus('Stream restart detected: counter reset from #' . $rSegNum . ' to #' . $rFreshSeg . '. Jumping.');
						$rSegNum = $rFreshSeg;
						$rWaitCount = 0;
						$rLastProgressTime = time();
					} else {
						$rWaitedSecs = time() - $rLastProgressTime;
						$this->logStatus('Waiting for segment #' . $rSegNum . ' (seg=' . ($rSegExists ? 'yes' : 'no') . ' next=' . ($rNextExists ? 'yes' : 'no') . ') waited=' . round($rWaitCount * 10 / 1000, 1) . 's total_stall=' . $rWaitedSecs . 's');
						if ($rWaitedSecs >= 300) {
							$this->logStatus('No new segments for 300s, stimer appears stuck. Exiting.');
							break;
						}
					}
				}
				usleep(10000);
				continue;
			}

			$rWaitCount = 0;
			$rLastProgressTime = time();
			$rSegSize = @filesize($rSegFile);
			$this->logStatus('Reading completed segment #' . $rSegNum . ' (' . number_format($rSegSize / 1024, 1) . ' KB).');

			// Stream the completed segment into the current archive minute file.
			// A minute boundary check inside the inner loop triggers file rotation.
			$rSegFP = fopen($rSegFile, 'r');
			while (!feof($rSegFP)) {
				// Rotate to a new archive minute file when the wall-clock minute changes.
				if (gmdate('Y-m-d:H-i') != $rFileTime) {
					fflush($rWriteFile);
					fclose($rWriteFile);
					$rOldFile = ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts';
					if ($rBytesWritten === 0) {
						// Archive started mid-minute or rotation fired before any data
						// arrived — discard the empty file rather than leaving a stub.
						@unlink($rOldFile);
						$this->logStatus('Discarded empty segment file: ' . $rFileTime . '.ts (no data written).');
					} else {
						if (!file_exists(ARCHIVE_PATH . $rStreamID)) {
							mkdir(ARCHIVE_PATH . $rStreamID);
						}
						// Find the first keyframe byte offset for timeshift seeking
						// and write it to a companion .offset file.
						$rOffset = (StreamUtils::findKeyframe($rOldFile) ?: 0);
						file_put_contents($rOldFile . '.offset', $rOffset);
						$this->logStatus('Segment closed: ' . $rFileTime . '.ts — ' . number_format($rBytesWritten / 1024, 1) . ' KB, reads=' . $rLoopIters . ', src_seg=#' . $rSegNum);
					}
					$rFileTime = gmdate('Y-m-d:H-i');
					$this->logStatus('Rotated to new segment file ' . $rFileTime . '.ts.');
					$rWriteFile = fopen(ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts', 'a');
					$rBytesWritten = 0;
					$rLoopIters = 0;
				}

				$rChunk = fread($rSegFP, 65536);
				if ($rChunk === false || $rChunk === '') {
					break;
				}
				$rLoopIters++;
				$rBytesWritten += strlen($rChunk);
				$rTotalBytesWritten += strlen($rChunk);
				$rWriteResult = fwrite($rWriteFile, $rChunk);
				if ($rWriteResult === false) {
					$this->logStatus('ERROR: fwrite failed for ' . $rFileTime . '.ts — disk full or permission error.');
					break 2;
				}
			}
			fclose($rSegFP);
			$rSegNum++;
		}

		// Flush and close the last open archive minute file after the loop ends.
		fflush($rWriteFile);
		fclose($rWriteFile);
		$rFinalFile = ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts';
		if ($rBytesWritten === 0) {
			@unlink($rFinalFile);
		} else {
			$rOffset = (StreamUtils::findKeyframe($rFinalFile) ?: 0);
			file_put_contents($rFinalFile . '.offset', $rOffset);
		}
		$this->logStatus('Archive loop stopped: source process ended or playlist disappeared.');

		return 0;
	}

	/**
	 * Writes a timestamped status line to stdout.
	 *
	 * Format: [archive][<pid>][<UTC datetime>] <message>
	 *
	 * @param string $rMessage Human-readable status message.
	 */
	private function logStatus($rMessage): void {
		echo '[archive][' . getmypid() . '][' . gmdate('Y-m-d H:i:s') . '] ' . $rMessage . "\n";
	}

	/**
	 * Removes the oldest archive minute files when the retention limit is exceeded.
	 *
	 * Retention limit = $rDuration days × 24 h × 60 min files per day.
	 * Files are listed oldest-first via `ls -tr` and deleted until the count
	 * is within the allowed limit.
	 *
	 * @param int $rStreamID Stream identifier used to build the archive path.
	 * @param int $rDuration Maximum retention duration in days.
	 */
	private function deleteSegments($rStreamID, $rDuration): void {
		$rSegmentCount = intval(count(scandir(ARCHIVE_PATH . $rStreamID . '/')) - 2);
		if ($rDuration * 24 * 60 < $rSegmentCount) {
			$rDelta = $rSegmentCount - $rDuration * 24 * 60;
			$this->logStatus('Segment limit exceeded, removing ' . $rDelta . ' old file(s).');
			$rFiles = array_values(array_filter(explode("\n", shell_exec('ls -tr ' . ARCHIVE_PATH . intval($rStreamID) . " | sed -e 's/\\s\\+/\\n/g'"))));
			for ($i = 0; $i < $rDelta; $i++) {
				if (!empty($rFiles[$i])) {
					unlink(ARCHIVE_PATH . $rStreamID . '/' . $rFiles[$i]);
					$this->logStatus('Deleted old segment: ' . $rFiles[$i]);
				}
			}
		}
	}

	/**
	 * Ensures no previous archive worker is running for this stream.
	 *
	 * Reads the .archive PID marker file. If the stored PID belongs to an
	 * active TVArchive process for this stream, it is killed with SIGKILL.
	 * If no PID file exists, any stale process is killed by its process title.
	 * The current PID is then written to the marker file.
	 *
	 * @param int $rStreamID Stream identifier.
	 */
	private function checkRunning($rStreamID): void {
		clearstatcache(true);
		$rPID = null;
		if (file_exists(STREAMS_PATH . $rStreamID . '_.archive')) {
			$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.archive'));
		}
		if (empty($rPID)) {
			$this->logStatus('No archive PID file found, killing stale TVArchive process by title if present.');
			shell_exec("kill -9 `ps -ef | grep 'TVArchive\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
		} else {
			$this->logStatus('Found archive PID file with PID: ' . $rPID);
			if ($this->isArchiveProcessForStream($rPID, $rStreamID)) {
				posix_kill($rPID, 9);
			}
		}
		file_put_contents(STREAMS_PATH . $rStreamID . '_.archive', getmypid());
		$this->logStatus('Wrote current PID to archive marker file.');
	}
	
	/**
	 * Checks whether a given PID is an active TVArchive worker for this stream.
	 *
	 * Inspects /proc/{pid}/cmdline and /proc/{pid}/comm for the process title
	 * "TVArchive[{streamID}]" set via cli_set_process_title().
	 *
	 * @param int $rPID      PID to check.
	 * @param int $rStreamID Stream identifier to match against the process title.
	 * @return bool True if the process is an archive worker for this stream.
	 */
	private function isArchiveProcessForStream($rPID, $rStreamID): bool {
		if (!is_numeric($rPID) || 0 >= intval($rPID) || !file_exists('/proc/' . $rPID)) {
			return false;
		}

		$rCmdline = str_replace("\0", ' ', @file_get_contents('/proc/' . $rPID . '/cmdline'));
		if (strpos($rCmdline, 'TVArchive[' . $rStreamID . ']') !== false) {
			return true;
		}

		$rComm = trim(@file_get_contents('/proc/' . $rPID . '/comm'));
		return $rComm == 'TVArchive[' . $rStreamID . ']';
	}
}
