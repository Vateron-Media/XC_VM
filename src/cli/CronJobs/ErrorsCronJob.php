<?php

require_once __DIR__ . '/../CronTrait.php';

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

        $rIgnoreErrors = array('the user-agent option is deprecated', 'last message repeated', 'deprecated', 'packets poorly interleaved', 'invalid timestamps', 'timescale not set', 'frame size not set', 'non-monotonous dts in output stream', 'invalid dts', 'no trailing crlf', 'failed to parse extradata', 'truncated', 'missing picture', 'non-existing pps', 'clipping', 'out of range', 'cannot use rename on non file protocol', 'end of file', 'stream ends prematurely');

        $this->loadCron($rIgnoreErrors);

        return 0;
    }

    private function sqlValue($value, bool $isNumeric = false): string {
        global $db;

        if ($value === null || $value === '') {
            return 'NULL';
        }
        if ($isNumeric) {
            if (!is_numeric($value)) {
                return 'NULL';
            }
            return (string) ((int) $value);
        }
        return $db->escape($value);
    }

    private function parseLog(string $logFile): string {
        global $db;

        if (!file_exists($logFile)) {
            return '';
        }

        $fp = fopen($logFile, 'r');
        if (!$fp) {
            return '';
        }

        $hashes = [];
        $query = '';

        while (!feof($fp)) {
            $line = trim(fgets($fp));
            if ($line === '') continue;

            $row = json_decode(base64_decode($line), true);
            if (!is_array($row)) continue;

            // Поддержка обоих форматов: legacy (log_*) и текущий (message/extra)
            $rLogMessage = (string) ($row['log_message'] ?? ($row['message'] ?? ''));
            $rLogExtra = (string) ($row['log_extra'] ?? ($row['extra'] ?? ''));
            $rLogType = (string) ($row['type'] ?? 'unknown');
            $rLogLine = (int) ($row['line'] ?? 0);
            $rLogTime = (int) ($row['time'] ?? time());
            $rLogFile = (string) ($row['file'] ?? '');
            $rLogEnv = (string) ($row['env'] ?? php_sapi_name());

            if (
                stripos($rLogMessage, 'server has gone away') !== false ||
                stripos($rLogMessage, 'socket error on read socket') !== false ||
                stripos($rLogMessage, 'connection lost') !== false
            ) {
                continue;
            }

            $hash = md5(
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

            $query .= sprintf(
                "(%d,%s,%s,%s,%s,%s,%s,%s,%s),",
                SERVER_ID,
                $this->sqlValue($rLogType),
                $this->sqlValue($rLogMessage),
                $this->sqlValue($rLogExtra),
                $this->sqlValue($rLogLine, true),
                $this->sqlValue($rLogTime, true),
                $this->sqlValue($rLogFile),
                $this->sqlValue($rLogEnv),
                $this->sqlValue($hash)
            );
        }

        fclose($fp);

        return rtrim($query, ',');
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

        $rQuery = '';
        foreach (array(STREAMS_PATH) as $rPath) {
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
                                if (!(empty($rError) || $this->inArray($rIgnoreErrors, $rError))) {
                                    if (SettingsManager::getAll()['stream_logs_save']) {
                                        $rQuery .= '(' . $rStreamID . ',' . SERVER_ID . ',' . time() . ',' . $db->escape($rError) . '),';
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

        if (SettingsManager::getAll()['stream_logs_save'] && !empty($rQuery)) {
            $rQuery = rtrim($rQuery, ',');
            $db->query('INSERT INTO `streams_errors` (`stream_id`,`server_id`,`date`,`error`) VALUES ' . $rQuery . ';');
        }

        $rLog = LOGS_TMP_PATH . 'error_log.log';
        if (file_exists($rLog)) {
            $rQuery = $this->parseLog(LOGS_TMP_PATH . 'error_log.log');
            if ($rQuery !== '') {
                $rInserted = $db->query("INSERT IGNORE INTO panel_logs(server_id, type, log_message, log_extra, line, date, file, env, `unique`) VALUES {$rQuery};");
                if ($rInserted) {
                    unlink($rLog);
                }
            }
        }
    }
}
