<?php

/**
 * StreamsLogsCronJob — streams logs cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../CronTrait.php';

class StreamsLogsCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:streams_logs';
    }

    public function getDescription(): string {
        return 'Cron: import stream logs into DB';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        $this->initCron('XC_VM[Stream Logs]');

        global $db;

        $rLog = LOGS_TMP_PATH . 'stream_log.log';
        if (!file_exists($rLog)) {
            return 0;
        }

        $rQuery = rtrim($this->parseLog($rLog), ',');
        if (!empty($rQuery)) {
            $db->query('INSERT INTO `streams_logs` (`stream_id`,`server_id`,`action`,`source`,`date`) VALUES ' . $rQuery . ';');
        }
        unlink($rLog);

        return 0;
    }

    private function parseLog(string $rLog): string {
        $rQuery = '';
        if (!file_exists($rLog)) {
            return $rQuery;
        }

        $rFP = fopen($rLog, 'r');
        while (!feof($rFP)) {
            $rLine = trim(fgets($rFP));
            if (!empty($rLine)) {
                $rLine = json_decode(base64_decode($rLine), true);
                if (!$rLine['stream_id']) {
                    break;
                }
                $rQuery .= '(' . $rLine['stream_id'] . ',' . SERVER_ID . ",'" . $rLine['action'] . "','" . $rLine['source'] . "','" . $rLine['time'] . "'),";
                break;
            }
        }
        fclose($rFP);

        return $rQuery;
    }
}
