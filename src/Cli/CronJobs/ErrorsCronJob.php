<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Cluster\Redactor;
use XcVm\Core\Config\SettingsManager;

/**
 * ErrorsCronJob — errors cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ErrorsCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:errors';
	}

	public function getDescription(): string {
		return 'Cron: collect stream and panel errors from logs';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->initCron('XC_VM[Errors]');

		$rIgnoreErrors = ['the user-agent option is deprecated', 'last message repeated', 'deprecated', 'packets poorly interleaved', 'invalid timestamps', 'timescale not set', 'frame size not set', 'non-monotonous dts in output stream', 'invalid dts', 'no trailing crlf', 'failed to parse extradata', 'truncated', 'missing picture', 'non-existing pps', 'clipping', 'out of range', 'cannot use rename on non file protocol', 'end of file', 'stream ends prematurely'];

		$this->loadCron($rIgnoreErrors);

		return 0;
	}

	/** Empty → NULL; numeric columns as int (NULL when not numeric). */
	private function sqlValue($value, bool $isNumeric = false): string|int|null {
		if ($value === null || $value === '') {
			return null;
		}
		if ($isNumeric) {
			return is_numeric($value) ? (int) $value : null;
		}
		return (string) $value;
	}

	/** @return list<array<string, mixed>> panel_logs rows, deduplicated. */
	private function parseLog(string $logFile): array {
		if (!file_exists($logFile)) {
			return [];
		}

		$fp = fopen($logFile, 'r');
		if (!$fp) {
			return [];
		}

		$hashes = [];
		$rows = [];

		while (!feof($fp)) {
			$line = trim(fgets($fp));
			if ($line === '') {
				continue;
			}

			$row = json_decode(base64_decode($line), true);
			if (!is_array($row)) {
				continue;
			}

			// Поддержка обоих форматов: legacy (log_*) и текущий (message/extra)
			$rLogMessage = Redactor::redact((string) ($row['log_message'] ?? ($row['message'] ?? '')));
			$rLogExtra = Redactor::redact((string) ($row['log_extra'] ?? ($row['extra'] ?? '')));
			$rLogType = (string) ($row['type'] ?? 'unknown');
			$rLogLine = (int) ($row['line'] ?? 0);
			$rLogTime = (int) ($row['time'] ?? time());
			$rLogFile = (string) ($row['file'] ?? '');
			$rLogEnv = (string) ($row['env'] ?? php_sapi_name());
			// Panel version frozen when the error occurred (Logger::log). Empty for
			// legacy records written before the field existed.
			$rLogVersion = (string) ($row['version'] ?? '');
			// Prefer the origin server stamped into the record (FileLogger); fall
			// back to this node's SERVER_ID for legacy files written before the
			// field existed. This is what lets a panel_logs row show LB vs MAIN.
			$rLogServerID = (isset($row['server_id']) && is_numeric($row['server_id']))
				? (int) $row['server_id']
				: SERVER_ID;

			if (stripos($rLogMessage, 'server has gone away') !== false
				|| stripos($rLogMessage, 'socket error on read socket') !== false
				|| stripos($rLogMessage, 'connection lost') !== false
			) {
				continue;
			}

			// server_id is part of the key so the SAME error from LB and MAIN keeps
			// two distinct panel_logs rows — INSERT IGNORE on `unique` would
			// otherwise collapse them into one and lose the per-server attribution.
			$hash = md5(
				$rLogServerID .
				$rLogType .
				$rLogMessage .
				$rLogExtra .
				$rLogFile .
				$rLogLine
			);

			if (isset($hashes[$hash])) {
				continue;
			}
			$hashes[$hash] = true;

			$rows[] = [
				'server_id'   => $rLogServerID,
				'type'        => $this->sqlValue($rLogType),
				'log_message' => $this->sqlValue($rLogMessage),
				'log_extra'   => $this->sqlValue($rLogExtra),
				'line'        => $this->sqlValue($rLogLine, true),
				'date'        => $this->sqlValue($rLogTime, true),
				'file'        => $this->sqlValue($rLogFile),
				'env'         => $this->sqlValue($rLogEnv),
				'version'     => $this->sqlValue($rLogVersion),
				'unique'      => $hash,
			];
		}

		fclose($fp);

		return $rows;
	}

	private function inArray(array $needles, string $haystack): bool {
		foreach ($needles as $needle) {
			if (stristr($haystack, $needle)) {
				return true;
			}
		}
		return false;
	}

	private function loadCron(array $rIgnoreErrors): void {
		global $db;

		$rErrorRows = [];
		foreach ([STREAMS_PATH] as $rPath) {
			if ($rHandle = opendir($rPath)) {
				while (false !== ($fileEntry = readdir($rHandle))) {
					if ($fileEntry != '.' && $fileEntry != '..' && is_file($rPath . $fileEntry)) {
						$rFile = $rPath . $fileEntry;
						$rPathInfo = pathinfo($fileEntry);
						$rStreamID = (int) ($rPathInfo['filename'] ?? 0);
						$rExtension = $rPathInfo['extension'] ?? '';
						if ($rExtension == 'errors' && 0 < $rStreamID) {
							$rErrors = preg_split('/\r\n|\r|\n/', (string) file_get_contents($rFile));
							foreach ($rErrors as $rError) {
								$rError = trim((string) $rError);
								if (!empty($rError) && !$this->inArray($rIgnoreErrors, $rError)) {
									if (SettingsManager::get('stream_logs_save')) {
										$rErrorRows[] = ['stream_id' => $rStreamID, 'server_id' => SERVER_ID, 'date' => time(), 'error' => Redactor::redact($rError)];
									}
								}
							}
							unlink($rFile);
						}
					}
				}
				closedir($rHandle);
			}
		}

		if (SettingsManager::get('stream_logs_save') && $rErrorRows !== []) {
			LogSink::write('stream_error', $rErrorRows, $db);
		}

		$rLog = LOGS_TMP_PATH . 'error_log.log';
		if (file_exists($rLog)) {
			$rRows = $this->parseLog(LOGS_TMP_PATH . 'error_log.log');
			if ($rRows !== [] && LogSink::write('panel_error', $rRows, $db)) {
				unlink($rLog);
			}
		}
	}
}
