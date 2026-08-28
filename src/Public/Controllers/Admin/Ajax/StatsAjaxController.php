<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Server\ServerRepository;

/**
 * Admin-ajax controller for the "Stats & Graphs" group.
 *
 * Extracted from the legacy `admin/api.php`. Actions: graph_stats, stats,
 * header_stats. Block logic ported faithfully (scaffolding via gate/ok/fail
 * from {@see BaseAjaxController}; empty-then / nested if-else cascades flattened
 * — behaviour-preserving; comments English).
 *
 * `graph_stats` has no per-action permission gate in api.php (only the shared
 * admin-session + XHR guard); that is preserved. All three responses are custom
 * JSON shapes kept via `JSON_PARTIAL_OUTPUT_ON_ERROR`.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class StatsAjaxController extends BaseAjaxController {

    /** action=graph_stats — per-minute time-series for the dashboard graphs. */
    public function graphStats(): never {
        $this->requireXhr();

        global $db, $rServers;
        $rLimit = 3600;
        $rTime = AdminHelpers::roundUpToAny(time(), 10);
        $rNearestRange = $rTime - $rLimit;
        $rPeriod = 60;
        $rStatsRange = array();

        foreach (range($rNearestRange, $rTime, $rPeriod) as $i) {
            $rStatsRange[] = $i;
        }
        $rServerStats = array();

        if (RequestManager::has('server_id')) {
            $db->query('SELECT `server_id`, `time`, `cpu`, `iostat_info`, `total_mem_used_percent`, `connections`, `streams`, `users`, `total_users`, `bytes_received`, `bytes_sent` FROM `servers_stats` WHERE `time` >= ? AND `server_id` = ? ORDER BY `time` DESC;', $rNearestRange, RequestManager::get('server_id'));
        } else {
            $db->query('SELECT `server_id`, `time`, `cpu`, `iostat_info`, `total_mem_used_percent`, `connections`, `streams`, `users`, `total_users`, `bytes_received`, `bytes_sent` FROM `servers_stats` WHERE `server_id` IN (SELECT `id` FROM `servers` WHERE `server_type` = 0) AND `time` >= ? ORDER BY `time` DESC;', $rNearestRange);
        }

        foreach ($db->get_rows() as $rRow) {
            // servers_stats may reference a server_id absent from $rServers (orphan stat
            // row from a deleted server); empty() skips the missing-key / null-offset
            // case without a warning — same intent: unknown or offline server -> skip.
            if (!empty($rServers[$rRow['server_id']]['server_online'])) {
                $rNearest = AdminHelpers::getNearest($rStatsRange, intval($rRow['time']));

                if (!isset($rStatsRange[$rNearest][intval($rRow['server_id'])])) {
                    $rServerStats[$rNearest][intval($rRow['server_id'])] = $rRow;
                }
            }
        }

        $rStats = array('cpu' => array(), 'memory' => array(), 'users' => array(), 'io' => array(), 'input' => array(), 'output' => array(), 'dates' => array(null, null));

        foreach (array_keys($rServerStats) as $rTime) {
            $rTotalCPU = 0;
            $rCPUCount = 0;
            $rTotalMem = 0;
            $rMemCount = 0;
            $rTotalIO = 0;
            $rIOCount = 0;
            $rTotalInput = 0;
            $rTotalOutput = 0;
            $rTotalConnections = 0;
            $rTotalStreams = 0;
            $rTotalUsers = 0;

            if (RequestManager::has('server_id')) {
                $rTotalUsers = $rServerStats[$rTime][RequestManager::get('server_id')]['users'] ?? 0;
            } else {
                $rTotalUsers = $rServerStats[$rTime][SERVER_ID]['total_users'] ?? 0;
            }

            foreach ($rServerStats[$rTime] as $rData) {
                $rTotalCPU += $rData['cpu'];
                $rCPUCount++;
                $rIOStat = json_decode($rData['iostat_info'], true);

                if ($rIOStat) {
                    $rTotalIO += $rIOStat['avg-cpu']['iowait'] ?? 0;
                    $rIOCount++;
                }

                $rTotalMem += $rData['total_mem_used_percent'];
                $rMemCount++;
                $rTotalConnections += $rData['connections'];
                $rTotalStreams += $rData['streams'];
                $rTotalInput += $rData['bytes_received'];
                $rTotalOutput += $rData['bytes_sent'];
            }

            if (!$rStats['dates'][0] || $rTime * 1000 < $rStats['dates'][0]) {
                $rStats['dates'][0] = $rTime * 1000;
            }

            if (!$rStats['dates'][1] || $rTime * 1000 > $rStats['dates'][1]) {
                $rStats['dates'][1] = $rTime * 1000;
            }

            $rStats['cpu'][] = array($rTime * 1000, $rCPUCount ? round($rTotalCPU / $rCPUCount, 2) : 0);
            $rStats['memory'][] = array($rTime * 1000, $rMemCount ? round($rTotalMem / $rMemCount, 2) : 0);
            $rStats['io'][] = array($rTime * 1000, $rIOCount ? round($rTotalIO / $rIOCount, 2) : 0);
            $rStats['connections'][] = array($rTime * 1000, $rTotalConnections);
            $rStats['streams'][] = array($rTime * 1000, $rTotalStreams);
            $rStats['users'][] = array($rTime * 1000, $rTotalUsers);
            $rStats['input'][] = array($rTime * 1000, round($rTotalInput / 125000, 0));
            $rStats['output'][] = array($rTime * 1000, round($rTotalOutput / 125000, 0));
        }

        $this->json($rStats, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** action=stats — dashboard summary (single server via server_id, or the whole fleet). */
    public function stats(): never {
        $this->requireXhr();
        $this->gate('adv', 'index');

        global $db;
        $rServers = ServerRepository::getAll(true);
        $rReturn = array('cpu' => 0, 'mem' => 0, 'io' => 0, 'fs' => 0, 'uptime' => '--', 'bytes_sent' => 0, 'bytes_received' => 0, 'open_connections' => 0, 'total_connections' => 0, 'online_users' => 0, 'total_users' => 0, 'total_streams' => 0, 'total_running_streams' => 0, 'offline_streams' => 0, 'requests_per_second' => 0, 'servers' => array());

        $rUptimeFallbackCache = array();
        $getFallbackUptime = function ($rServerID) use (&$db, &$rUptimeFallbackCache) {
            $rServerID = intval($rServerID);
            if (array_key_exists($rServerID, $rUptimeFallbackCache)) {
                return $rUptimeFallbackCache[$rServerID];
            }
            $db->query('SELECT `uptime` FROM `servers_stats` WHERE `server_id` = ? AND `uptime` IS NOT NULL AND `uptime` <> \'\'  ORDER BY `id` DESC LIMIT 1;', $rServerID);
            $rUptimeFallbackCache[$rServerID] = (0 < $db->num_rows()) ? strval($db->get_row()['uptime']) : '';
            return $rUptimeFallbackCache[$rServerID];
        };

        if (SettingsManager::get('redis_handler')) {
            $rReturn['total_users'] = SettingsManager::get('total_users');
        } else {
            $db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');

            if (0 < $db->num_rows()) {
                $rReturn['total_users'] = $db->num_rows();
            }
        }

        if (RequestManager::has('server_id')) {
            $rServerID = intval(RequestManager::get('server_id'));
            $rWatchDog = json_decode($rServers[$rServerID]['watchdog_data'], true);

            if (!is_array($rWatchDog)) {
                $rFallback = $getFallbackUptime($rServerID);

                if ($rFallback !== '') {
                    $rReturn['uptime'] = $rFallback;
                }
            } else {
                $rWatchdogUptime = trim(strval($rWatchDog['uptime'] ?? ''));
                $rReturn['uptime'] = $rWatchdogUptime !== '' ? $rWatchdogUptime : ($getFallbackUptime($rServerID) ?: '--');
                $rReturn['mem'] = round($rWatchDog['total_mem_used_percent'], 0);
                $rReturn['cpu'] = round($rWatchDog['cpu'], 0);

                if (isset($rWatchDog['iostat_info'])) {
                    $rReturn['io'] = round($rWatchDog['iostat_info']['avg-cpu']['iowait'] ?? 0, 0);
                }

                if (isset($rWatchDog['total_disk_space'])) {
                    $rReturn['fs'] = intval(($rWatchDog['total_disk_space'] - $rWatchDog['free_disk_space']) / $rWatchDog['total_disk_space'] * 100);
                }

                $rReturn['bytes_received'] = intval($rWatchDog['bytes_received']);
                $rReturn['bytes_sent'] = intval($rWatchDog['bytes_sent']);
            }

            $rReturn['requests_per_second'] = $rServers[$rServerID]['requests_per_second'];

            if (SettingsManager::get('redis_handler')) {
                $rReturn['open_connections'] = $rServers[$rServerID]['connections'];
                $rReturn['online_users'] = $rServers[$rServerID]['users'];

                foreach (array_keys($rServers) as $rSID) {
                    if ($rServers[$rSID]['server_online']) {
                        $rReturn['total_connections'] += $rServers[$rSID]['connections'];
                    }
                }
            } else {
                $db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0;', $rServerID);

                if (0 < $db->num_rows()) {
                    $rReturn['open_connections'] = $db->get_row()['count'];
                }

                $db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0;');

                if (0 < $db->num_rows()) {
                    $rReturn['total_connections'] = $db->get_row()['count'];
                }

                $db->query('SELECT `activity_id` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0 GROUP BY `user_id`;', $rServerID);

                if (0 < $db->num_rows()) {
                    $rReturn['online_users'] = $db->num_rows();
                }
            }

            $db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `stream_status` <> 2 AND `type` = 1;', $rServerID);

            if (0 < $db->num_rows()) {
                $rReturn['total_streams'] = $db->get_row()['count'];
            }

            $db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0 AND `type` = 1;', $rServerID);

            if (0 < $db->num_rows()) {
                $rReturn['total_running_streams'] = $db->get_row()['count'];
            }

            $db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `type` = 1 AND (`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0);', $rServerID);

            if (0 < $db->num_rows()) {
                $rReturn['offline_streams'] = $db->get_row()['count'];
            }

            $rReturn['network_guaranteed_speed'] = $rServers[$rServerID]['network_guaranteed_speed'];
        } else {
            $rTotalConnections = 0;

            if (!SettingsManager::get('redis_handler')) {
                $db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0;');
                $rTotalConnections = (0 < $db->num_rows()) ? $db->get_row()['count'] : 0;
                $db->query('SELECT `activity_id` AS `count` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');
                $db->query('SELECT `user_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');
                $rReturn['online_users'] = $db->num_rows();
                $rReturn['open_connections'] = $rTotalConnections;
            }

            $rTotalStreams = $rOnlineStreams = $rOfflineStreams = $rOnlineUsers = $rOpenConnections = array();
            $db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `server_id`;');

            foreach ($db->get_rows() as $rRow) {
                $rOpenConnections[intval($rRow['server_id'])] = intval($rRow['count']);
            }
            $db->query('SELECT `server_id`, COUNT(DISTINCT(`user_id`)) AS `count` FROM `lines_live` GROUP BY `server_id`;');

            foreach ($db->get_rows() as $rRow) {
                $rOnlineUsers[intval($rRow['server_id'])] = intval($rRow['count']);
            }
            $db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `stream_status` <> 2 AND `type` = 1 GROUP BY `server_id`;');

            foreach ($db->get_rows() as $rRow) {
                $rTotalStreams[intval($rRow['server_id'])] = intval($rRow['count']);
            }
            $db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `type` = 1 AND (`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0) GROUP BY `server_id`;');

            foreach ($db->get_rows() as $rRow) {
                $rOfflineStreams[intval($rRow['server_id'])] = intval($rRow['count']);
            }
            $db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `pid` > 0 AND `type` = 1 GROUP BY `server_id`;');

            foreach ($db->get_rows() as $rRow) {
                $rOnlineStreams[intval($rRow['server_id'])] = intval($rRow['count']);
            }

            foreach (array_keys($rServers) as $rServerID) {
                if ($rServers[$rServerID]['server_online']) {
                    $rArray = array();

                    if (SettingsManager::get('redis_handler')) {
                        $rArray['open_connections'] = $rServers[$rServerID]['connections'];
                        $rReturn['open_connections'] += $rServers[$rServerID]['connections'];
                        $rReturn['total_connections'] += $rServers[$rServerID]['connections'];
                        $rArray['online_users'] = $rServers[$rServerID]['users'];
                        $rReturn['online_users'] += $rServers[$rServerID]['users'];
                        $rReturn['total_users'] += $rServers[$rServerID]['users'];
                    } else {
                        $rArray['open_connections'] = $rOpenConnections[$rServerID] ?? 0;
                        $rArray['online_users']     = $rOnlineUsers[$rServerID] ?? 0;
                        $rArray['total_connections'] = $rTotalConnections;
                    }

                    $rArray['requests_per_second'] = $rServers[$rServerID]['requests_per_second'];
                    $rArray['total_streams'] = ($rTotalStreams[$rServerID] ?? 0);
                    $rArray['total_running_streams'] = ($rOnlineStreams[$rServerID] ?? 0);
                    $rArray['offline_streams'] = ($rOfflineStreams[$rServerID] ?? 0);
                    $rArray['network_guaranteed_speed'] = $rServers[$rServerID]['network_guaranteed_speed'];
                    $rWatchDog = json_decode($rServers[$rServerID]['watchdog_data'], true);

                    if (is_array($rWatchDog)) {
                        $rWatchdogUptime = trim(strval($rWatchDog['uptime'] ?? ''));
                        $rArray['uptime'] = $rWatchdogUptime !== '' ? $rWatchdogUptime : ($getFallbackUptime($rServerID) ?: '--');
                        $rArray['mem'] = round($rWatchDog['total_mem_used_percent'], 0);
                        $rArray['cpu'] = round($rWatchDog['cpu'], 0);

                        if (isset($rWatchDog['iostat_info'])) {
                            $rArray['io'] = round($rWatchDog['iostat_info']['avg-cpu']['iowait'] ?? 0, 0);
                        }

                        if (isset($rWatchDog['total_disk_space'])) {
                            $rArray['fs'] = intval(($rWatchDog['total_disk_space'] - $rWatchDog['free_disk_space']) / $rWatchDog['total_disk_space'] * 100);
                        }

                        $rArray['bytes_received'] = intval($rWatchDog['bytes_received']);
                        $rArray['bytes_sent'] = intval($rWatchDog['bytes_sent']);
                        $rReturn['bytes_received'] += intval($rWatchDog['bytes_received']);
                        $rReturn['bytes_sent'] += intval($rWatchDog['bytes_sent']);
                    } else {
                        $rFallback = $getFallbackUptime($rServerID);
                        $rArray['uptime'] = $rFallback !== '' ? $rFallback : '--';
                    }

                    $rArray['server_id'] = $rServerID;
                    $rArray['server_type'] = $rServers[$rServerID]['server_type'];
                    $rReturn['servers'][] = $rArray;
                }
            }

            foreach ($rReturn['servers'] as $rServerArray) {
                $rReturn['total_streams'] += $rServerArray['total_streams'];
                $rReturn['total_running_streams'] += $rServerArray['total_running_streams'];
                $rReturn['offline_streams'] += $rServerArray['offline_streams'];
            }
            $rReturn['online_users'] = SettingsManager::get('total_users');
        }

        $this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** action=header_stats — compact fleet totals for the top navbar. */
    public function headerStats(): never {
        $this->requireXhr();
        $this->gate('adv', 'index');

        global $db, $rServers;
        $rReturn = array('bytes_sent' => 0, 'bytes_received' => 0, 'total_connections' => 0, 'total_users' => 0, 'total_running_streams' => 0, 'offline_streams' => 0);

        if (!SettingsManager::get('redis_handler')) {
            $db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0;');

            if (0 < $db->num_rows()) {
                $rReturn['total_connections'] = $db->get_row()['count'];
            }

            $db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');

            if (0 < $db->num_rows()) {
                $rReturn['total_users'] = $db->num_rows();
            }
        } else {
            $rReturn['total_users'] = SettingsManager::get('total_users');
        }

        $rOnlineCount = $rOfflineCount = array();
        $db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `pid` > 0 AND `type` = 1 GROUP BY `server_id`;');

        foreach ($db->get_rows() as $rRow) {
            $rOnlineCount[intval($rRow['server_id'])] = intval($rRow['count']);
        }
        $db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `type` = 1 AND (`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0) GROUP BY `server_id`;');

        foreach ($db->get_rows() as $rRow) {
            $rOfflineCount[intval($rRow['server_id'])] = intval($rRow['count']);
        }

        foreach (array_keys($rServers) as $rServerID) {
            if ($rServers[$rServerID]['server_online']) {
                if (SettingsManager::get('redis_handler')) {
                    $rReturn['total_connections'] += $rServers[$rServerID]['connections'];
                }

                $rReturn['total_running_streams'] += ($rOnlineCount[$rServerID] ?? 0);
                $rReturn['offline_streams'] += ($rOfflineCount[$rServerID] ?? 0);
                $rWatchDog = json_decode($rServers[$rServerID]['watchdog_data'], true);

                if (is_array($rWatchDog)) {
                    $rReturn['bytes_received'] += intval($rWatchDog['bytes_received']);
                    $rReturn['bytes_sent'] += intval($rWatchDog['bytes_sent']);
                }
            }
        }

        $this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
