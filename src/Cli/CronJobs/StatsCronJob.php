<?php

/**
 * StatsCronJob — stats cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../CronTrait.php';

class StatsCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:stats';
    }

    public function getDescription(): string {
        return 'Cron: recalculate stream statistics (rating, uptime, connections)';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        $this->initCron('XC_VM[Stats]');

        $rTimeout = 60;
        set_time_limit($rTimeout);
        ini_set('max_execution_time', $rTimeout);

        $this->loadCron();

        return 0;
    }

    private function loadCron(): void {
        global $db;

        if (!ServerRepository::getAll()[SERVER_ID]['is_main']) {
            return;
        }

        $rTime = time();
        $rDates = array(
            'today' => array($rTime - 86400, $rTime),
            'week'  => array($rTime - 604800, $rTime),
            'month' => array($rTime - 2592000, $rTime),
            'all'   => array(0, $rTime),
        );

        $db->query('TRUNCATE `streams_stats`;');

        foreach ($rDates as $rType => $rDate) {
            $rStats = array();

            $db->query('SELECT `stream_id`, COUNT(*) AS `connections`, SUM(`date_end` - `date_start`) AS `time`, COUNT(DISTINCT(`user_id`)) AS `users` FROM `lines_activity` LEFT JOIN `streams` ON `streams`.`id` = `lines_activity`.`stream_id` WHERE `date_start` > ? AND `date_end` <= ? GROUP BY `stream_id`;', $rDate[0], $rDate[1]);
            if ($db->num_rows() > 0) {
                foreach ($db->get_rows() as $rRow) {
                    $rStats[$rRow['stream_id']] = array('rank' => 0, 'time' => intval($rRow['time']), 'connections' => $rRow['connections'], 'users' => $rRow['users']);
                }
            }

            $db->query('SELECT `stream_id`, SUM(`date_end` - `date_start`) AS `time` FROM `lines_activity` LEFT JOIN `streams` ON `streams`.`id` = `lines_activity`.`stream_id` WHERE `date_start` > ? AND `date_end` <= ? GROUP BY `stream_id` ORDER BY `time` DESC, `stream_id` DESC;', $rDate[0], $rDate[1]);
            if ($db->num_rows() > 0) {
                $rRank = 1;
                foreach ($db->get_rows() as $rRow) {
                    if (isset($rStats[$rRow['stream_id']])) {
                        $rStats[$rRow['stream_id']]['rank'] = $rRank;
                        $rRank++;
                    }
                }
            }

            foreach ($rStats as $rStreamID => $rArray) {
                $db->query('INSERT INTO `streams_stats`(`stream_id`, `rank`, `time`, `connections`, `users`, `type`) VALUES(?, ?, ?, ?, ?, ?);', $rStreamID, $rArray['rank'], $rArray['time'], $rArray['connections'], $rArray['users'], $rType);
            }
        }
    }
}
