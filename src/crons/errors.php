<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    set_time_limit(0);
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        cli_set_process_title('XC_VM[Errors]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        $rIgnoreErrors = array('the user-agent option is deprecated', 'last message repeated', 'deprecated', 'packets poorly interleaved', 'invalid timestamps', 'timescale not set', 'frame size not set', 'non-monotonous dts in output stream', 'invalid dts', 'no trailing crlf', 'failed to parse extradata', 'truncated', 'missing picture', 'non-existing pps', 'clipping', 'out of range', 'cannot use rename on non file protocol', 'end of file', 'stream ends prematurely');
        loadCron();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}

function sqlValue($value, $isNumeric = false) {
    global $db;

    if ($value === null || $value === '') {
        return 'NULL';
    }

    if ($isNumeric) {
        return (int)$value;  // unquoted integer
    }

    // Proper escaping using database method + normal single quotes
    return "'" . $value . "'";
}

function parseLog(string $logFile): string {
    global $db;

    if (!file_exists($logFile)) {
        return '';
    }

    $fp = fopen($logFile, 'r');
    if (!$fp) {
        return '';
    }

    $hashes = [];
    $query  = '';

    while (!feof($fp)) {
        $line = trim(fgets($fp));
        if ($line === '') continue;

        $row = json_decode(base64_decode($line), true);
        if (!is_array($row)) continue;

        // Noise filter
        if (
            stripos($row['log_message'] ?? '', 'server has gone away') !== false ||
            stripos($row['log_message'] ?? '', 'socket error on read socket') !== false ||
            stripos($row['log_message'] ?? '', 'connection lost') !== false
        ) {
            continue;
        }

        $hash = md5(
            ($row['type'] ?? '') .
                ($row['log_message'] ?? '') .
                ($row['log_extra'] ?? '') .
                ($row['file'] ?? '') .
                ($row['line'] ?? '')
        );

        if (isset($hashes[$hash])) {
            continue;
        }
        $hashes[$hash] = true;

        // DO NOT pre-escape here — remove the array_map line

        $query .= sprintf(
            "(%d,%s,%s,%s,%s,%s,%s,%s,%s),",
            SERVER_ID,
            sqlValue($row['type'] ?? null),           // string
            sqlValue($row['log_message'] ?? null),    // string
            sqlValue("'" . $db->escape($row['log_extra']) . "'" ?? null),      // string (can be long)
            sqlValue($row['line'] ?? null, true),     // numeric → unquoted
            sqlValue($row['time'] ?? null, true),     // numeric → unquoted
            sqlValue($row['file'] ?? null),           // string
            sqlValue($row['env'] ?? null),            // string
            sqlValue($hash)                           // string (hash)
        );
    }

    fclose($fp);

    return rtrim($query, ',');
}

function inArray($needles, $haystack) {
    foreach ($needles as $needle) {
        if (stristr($haystack, $needle)) {
            return true;
        }
    }
    return false;
}
function loadCron() {
    global $rIgnoreErrors;
    global $db;
    $rQuery = '';
    foreach (array(STREAMS_PATH) as $rPath) {
        if ($rHandle = opendir($rPath)) {
            while (false !== ($fileEntry = readdir($rHandle))) {
                if ($fileEntry != '.' && $fileEntry != '..' && is_file($rPath . $fileEntry)) {
                    $rFile = $rPath . $fileEntry;
                    list($rStreamID, $rExtension) = explode('.', $fileEntry);
                    if ($rExtension == 'errors') {
                        $rErrors = array_values(array_unique(array_map('trim', explode("\n", file_get_contents($rFile)))));
                        foreach ($rErrors as $rError) {
                            if (!(empty($rError) || inArray($rIgnoreErrors, $rError))) {
                                if (CoreUtilities::$rSettings['stream_logs_save']) {
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
    if (CoreUtilities::$rSettings['stream_logs_save'] && !empty($rQuery)) {
        $rQuery = rtrim($rQuery, ',');
        $db->query('INSERT INTO `streams_errors` (`stream_id`,`server_id`,`date`,`error`) VALUES ' . $rQuery . ';');
    }
    $rLog = LOGS_TMP_PATH . 'error_log.log';
    if (file_exists($rLog)) {
        $rQuery = parseLog(LOGS_TMP_PATH . 'error_log.log');
        if ($rQuery !== '') {
            $db->query("INSERT IGNORE INTO panel_logs(server_id, type, log_message, log_extra, line, date, file, env, `unique`) VALUES {$rQuery};");
            unlink($rLog);
        }
    }
}
function shutdown() {
    global $db;
    global $rIdentifier;
    if (is_object($db)) {
        $db->close_mysql();
    }
    @unlink($rIdentifier);
}
