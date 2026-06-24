<?php

/**
 * LinesLogsCronJob — lines logs cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../CronTrait.php';

class LinesLogsCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:lines_logs';
    }

    public function getDescription(): string {
        return 'Cron: import client request logs into DB';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        $this->initCron('XC_VM[Lines Logs]');
        $this->loadCron();

        return 0;
    }

    private function loadCron(): void {
        global $db;

        $rLog = LOGS_TMP_PATH . 'client_request.log';
        if (!file_exists($rLog)) {
            return;
        }

        $rQuery = rtrim($this->parseLog($rLog, $db), ',');
        if (!empty($rQuery)) {
            $db->query('INSERT INTO `lines_logs` (`stream_id`,`user_id`,`client_status`,`query_string`,`user_agent`,`ip`,`extra_data`,`date`) VALUES ' . $rQuery . ';');
        }
        unlink($rLog);
    }

    private function parseLog(string $rLog, $db): string {
        $rQuery = '';
        $rFP = fopen($rLog, 'r');
        while (!feof($rFP)) {
            $rLine = trim(fgets($rFP));
            if (!empty($rLine)) {
                $rLine = json_decode(base64_decode($rLine), true);
                $rLine = array_map(array($db, 'escape'), $rLine);
                $rQuery .= '(' . $rLine['stream_id'] . ',' . $rLine['user_id'] . ',' . $rLine['action'] . ',' . $rLine['query_string'] . ',' . $rLine['user_agent'] . ',' . $rLine['user_ip'] . ',' . $rLine['extra_data'] . ',' . $rLine['time'] . '),';
                break;
            }
        }
        fclose($rFP);
        return $rQuery;
    }
}
