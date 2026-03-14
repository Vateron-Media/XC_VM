<?php

require_once __DIR__ . '/../CronTrait.php';

class ActivityCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:activity';
    }

    public function getDescription(): string {
        return 'Cron: import user activity logs into DB';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        $this->initCron('XC_VM[Activity]');
        $this->loadCron();

        return 0;
    }

    private function loadCron(): void {
        global $db;

        $rLogFile = LOGS_TMP_PATH . 'activity';
        $rUpdateQuery = $rQuery = '';
        $rUpdates = array();
        $rCount = 0;

        if (!file_exists($rLogFile)) {
            return;
        }

        list($rQuery, $rUpdates, $rCount) = $this->parseLog($rLogFile, $db);
        unlink($rLogFile);

        if (0 >= $rCount) {
            return;
        }

        $rQuery = rtrim($rQuery, ',');
        if (empty($rQuery)) {
            return;
        }

        if (!$db->query('INSERT INTO `lines_activity` (`server_id`,`proxy_id`,`user_id`,`isp`,`external_device`,`stream_id`,`date_start`,`user_agent`,`user_ip`,`date_end`,`container`,`geoip_country_code`,`divergence`,`hmac_id`,`hmac_identifier`) VALUES ' . $rQuery)) {
            return;
        }

        $rFirstID = $db->last_insert_id();
        $i = 0;
        while ($i < $rCount) {
            $rUpdateQuery .= '(' . $rUpdates[$i][0] . ',' . $db->escape($rUpdates[$i][1]) . ',' . ($rFirstID + $i) . ',' . $db->escape($rUpdates[$i][2]) . '),';
            $i++;
        }

        $rUpdateQuery = rtrim($rUpdateQuery, ',');
        if (!empty($rUpdateQuery)) {
            $db->query('INSERT INTO `lines`(`id`,`last_ip`,`last_activity`,`last_activity_array`) VALUES ' . $rUpdateQuery . ' ON DUPLICATE KEY UPDATE `id`=VALUES(`id`), `last_ip`=VALUES(`last_ip`), `last_activity`=VALUES(`last_activity`), `last_activity_array`=VALUES(`last_activity_array`);');
        }
    }

    private function parseLog(string $rFile, $db): array {
        $rQuery = '';
        $rUpdates = array();
        $rCount = 0;

        if (!file_exists($rFile)) {
            return array($rQuery, $rUpdates, $rCount);
        }

        $rFP = fopen($rFile, 'r');
        while (!feof($rFP)) {
            $rLine = trim(fgets($rFP));
            if (!empty($rLine)) {
                $rLine = json_decode(base64_decode($rLine), true);
                if (!($rLine['server_id'] && $rLine['user_id'] && $rLine['stream_id'] && $rLine['user_ip'])) {
                    break;
                }
                $rUpdates[] = array($rLine['user_id'], $rLine['user_ip'], json_encode(array('date_end' => $rLine['date_end'], 'stream_id' => $rLine['stream_id'])));
                $rLine = array_map(array($db, 'escape'), $rLine);
                $rQuery .= '(' . $rLine['server_id'] . ',' . $rLine['proxy_id'] . ',' . $rLine['user_id'] . ',' . $rLine['isp'] . ',' . $rLine['external_device'] . ',' . $rLine['stream_id'] . ',' . $rLine['date_start'] . ',' . $rLine['user_agent'] . ',' . $rLine['user_ip'] . ',' . $rLine['date_end'] . ',' . $rLine['container'] . ',' . $rLine['geoip_country_code'] . ',' . $rLine['divergence'] . ',' . $rLine['hmac_id'] . ',' . $rLine['hmac_identifier'] . '),';
                $rCount++;
                break;
            }
        }
        fclose($rFP);

        return array($rQuery, $rUpdates, $rCount);
    }
}
