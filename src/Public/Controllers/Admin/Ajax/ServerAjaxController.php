<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Streaming\Health\ProcessChecker;

/**
 * Admin-ajax controller for the "Servers/Ops" group: rtmp_ip,
 * rollback_versions, server, proxy, fingerprint, restart_all_services,
 * restart_services, reboot_server, update_binaries, server_view, server_stats,
 * rtmp_kill, install_status, reinstall_server, fpm_status, update_all_servers,
 * update_all_binaries.
 *
 * Note: rtmp_kill echoes the raw {@see ApiClient::systemRequest()} response
 * rather than a JSON envelope.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ServerAjaxController extends BaseAjaxController {

    /** action=rtmp_ip — remove an RTMP IP from the blocklist. */
    public function rtmpIp(): never {
        $this->requireXhr();
        $this->gate('adv', 'add_rtmp');

        if (RequestManager::get('sub') == 'delete') {
            BlocklistService::deleteRTMPIP(RequestManager::get('ip'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=rollback_versions — list previous releases relative to a version. */
    public function rollbackVersions(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_server');

        // Optional base version (a specific server's version); defaults to
        // the MAIN panel version. Previous releases are resolved relative to it.
        $rBaseVersion = trim((string) RequestManager::get('version'));
        if (!preg_match('/^\d+\.\d+\.\d+$/', $rBaseVersion)) {
            $rBaseVersion = XC_VM_VERSION;
        }
        $rVersions = array();

        try {
            $rGit = new GitHubReleases(GIT_OWNER, GIT_REPO_MAIN, SettingsManager::get('update_channel'));
            $rGit->setTimeout(15);
            $rVersions = $rGit->getPreviousVersions($rBaseVersion, 5);
        } catch (\Throwable) {
            $rVersions = array();
        }

        $this->ok(array('current' => $rBaseVersion, 'versions' => $rVersions));
    }

    /** action=server — server operations (dispatches on sub). */
    public function server(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_server');

        global $db, $rServers;
        $rSub = RequestManager::get('sub');

        if ($rSub == 'delete') {
            if (isset($rServers[RequestManager::get('server_id')]) && $rServers[RequestManager::get('server_id')]['is_main'] == 0) {
                ServerRepository::deleteById(RequestManager::get('server_id'));
                $this->ok();
            }

            $this->fail();
        }

        if ($rSub == 'update') {
            foreach ($this->normalizeServerIds() as $rID) {
                $db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rID, time(), json_encode(array('action' => 'update')));
            }

            $this->ok();
        }

        if ($rSub == 'rollback') {
            $rVersion = trim((string) RequestManager::get('version'));

            if (!preg_match('/^\d+\.\d+\.\d+$/', $rVersion) || version_compare($rVersion, XC_VM_VERSION, '>=')) {
                $this->fail(array('error' => 'invalid_version'));
            }

            foreach ($this->normalizeServerIds() as $rID) {
                $db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rID, time(), json_encode(array('action' => 'rollback', 'version' => $rVersion)));
            }

            $this->ok();
        }

        if ($rSub == 'enable') {
            $db->query('UPDATE `servers` SET `enabled` = 1 WHERE `id` = ?;', RequestManager::get('server_id'));
            $this->ok();
        }

        if ($rSub == 'disable') {
            $db->query('UPDATE `servers` SET `enabled` = 0 WHERE `id` = ? AND `is_main` = 0;', RequestManager::get('server_id'));
            $this->ok();
        }

        if ($rSub == 'enable_proxy') {
            $db->query('UPDATE `servers` SET `enable_proxy` = 1 WHERE `id` = ?;', RequestManager::get('server_id'));
            $this->ok();
        }

        if ($rSub == 'disable_proxy') {
            $db->query('UPDATE `servers` SET `enable_proxy` = 0 WHERE `id` = ?;', RequestManager::get('server_id'));
            $this->ok();
        }

        if ($rSub == 'kill') {
            if (SettingsManager::get('redis_handler')) {
                foreach (ConnectionTracker::getRedisConnections(null, RequestManager::get('server_id'), null, true, false, false) as $rConnection) {
                    ConnectionTracker::closeConnection($rConnection);
                }
            } else {
                $db->query('SELECT * FROM `lines_live` WHERE `server_id` = ?;', RequestManager::get('server_id'));

                foreach ($db->get_rows() as $rRow) {
                    ConnectionTracker::closeConnection($rRow);
                }
            }

            $this->ok();
        }

        if (in_array($rSub, array('restart', 'start', 'stop'), true)) {
            // 'restart' additionally requires an already-running monitored stream.
            $rOnDemand = ($rSub == 'restart') ? ' AND `monitor_pid` > 0 AND `pid` > 0 AND `stream_status` = 0' : '';
            $rSignalSub = ($rSub == 'stop') ? 'stop' : 'start';
            $rStreamIDs = array();
            $db->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_id` = ? AND `on_demand` = 0' . $rOnDemand . ';', RequestManager::get('server_id'));

            if (0 < $db->num_rows()) {
                foreach ($db->get_rows() as $rRow) {
                    $rStreamIDs[] = intval($rRow['stream_id']);
                }
            }

            if (0 < count($rStreamIDs)) {
                ApiClient::request(array('action' => 'stream', 'sub' => $rSignalSub, 'stream_ids' => array_values($rStreamIDs), 'servers' => array(intval(RequestManager::get('server_id')))));
            }

            $this->ok();
        }

        $this->fail();
    }

    /** action=proxy — proxy-server operations (dispatches on sub). */
    public function proxy(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_server');

        global $db;
        $rSub = RequestManager::get('sub');

        if ($rSub == 'delete') {
            ServerRepository::deleteById(RequestManager::get('server_id'));
            $this->ok();
        }

        if ($rSub == 'enable') {
            $db->query('UPDATE `servers` SET `enabled` = 1 WHERE `id` = ?;', RequestManager::get('server_id'));
            $this->ok();
        }

        if ($rSub == 'disable') {
            $db->query('UPDATE `servers` SET `enabled` = 0 WHERE `id` = ?;', RequestManager::get('server_id'));
            $this->ok();
        }

        if ($rSub == 'update') {
            $db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', RequestManager::get('server_id'), time(), json_encode(array('action' => 'update')));
            $this->ok();
        }

        if ($rSub == 'kill') {
            if (SettingsManager::get('redis_handler')) {
                $rServerID = intval(RequestManager::get('server_id'));

                foreach ((ServerRepository::getAll()[$rServerID]['parent_id'] ?? array()) as $rParentID) {
                    foreach (ConnectionTracker::getRedisConnections(null, $rParentID, null, true, false, false) as $rConnection) {
                        if ($rConnection['proxy_id'] == RequestManager::get('server_id')) {
                            ConnectionTracker::closeConnection($rConnection);
                        }
                    }
                }
            } else {
                $db->query('SELECT * FROM `lines_live` WHERE `proxy_id` = ?;', RequestManager::get('server_id'));

                foreach ($db->get_rows() as $rRow) {
                    ConnectionTracker::closeConnection($rRow);
                }
            }

            $this->ok();
        }

        $this->fail();
    }

    /** action=fingerprint — broadcast an overlay to the active servers. */
    public function fingerprint(): never {
        $this->requireXhr();
        $this->gate('adv', 'fingerprint');

        global $db, $rServers;
        $rData = json_decode(RequestManager::get('data'), true);
        $rActiveServers = array();

        foreach ($rServers as $rServer) {
            $rServerError = ((360 < time() - $rServer['last_check_ago'] || $rServer['status'] == 2) && $rServer['is_main'] == 0 && $rServer['status'] != 3);

            if ($rServer['status'] == 1 && !$rServerError) {
                $rActiveServers[] = $rServer['id'];
            }
        }

        if (0 < $rData['id'] && 0 < $rData['font_size'] && 0 < strlen($rData['font_color']) && 0 < strlen($rData['xy_offset']) && (0 < strlen($rData['message']) || $rData['type'] < 3)) {
            if (SettingsManager::get('redis_handler')) {
                if (isset($rData['user'])) {
                    $rRows = ConnectionTracker::getRedisConnections($rData['id'], null, null, true, false, false);
                } else {
                    $rRows = ConnectionTracker::getRedisConnections(null, null, $rData['id'], true, false, false);
                }

                $rUserMap = $rUserIDs = array();

                foreach ($rRows as $rRow) {
                    if (!in_array($rRow['user_id'], $rUserIDs)) {
                        $rUserIDs[] = intval($rRow['user_id']);
                    }
                }

                if (0 < count($rUserIDs)) {
                    $db->query('SELECT `id`, `username` FROM `lines` WHERE `id` IN (' . implode(',', $rUserIDs) . ');');

                    foreach ($db->get_rows() as $rRow) {
                        $rUserMap[$rRow['id']] = $rRow['username'];
                    }
                }
            } else {
                if (isset($rData['user'])) {
                    $db->query('SELECT `lines_live`.`activity_id`, `lines_live`.`uuid`, `lines_live`.`user_id`, `lines_live`.`server_id`, `lines`.`username` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `user_id` = ?;', $rData['id']);
                } else {
                    $db->query('SELECT `lines_live`.`activity_id`, `lines_live`.`uuid`, `lines_live`.`user_id`, `lines_live`.`server_id`, `lines`.`username` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `stream_id` = ?;', $rData['id']);
                }

                $rRows = $db->get_rows();
            }

            if (count($rRows) > 0) {
                set_time_limit(360);
                ini_set('max_execution_time', 360);
                ini_set('default_socket_timeout', 15);

                foreach ($rRows as $rRow) {
                    if (in_array($rRow['server_id'], $rActiveServers)) {
                        $rArray = array('font_size' => $rData['font_size'], 'font_color' => $rData['font_color'], 'xy_offset' => $rData['xy_offset'], 'message' => '', 'uuid' => $rRow['uuid']);

                        if ($rData['type'] == 1) {
                            $rArray['message'] = $rRow['uuid'];
                        } elseif ($rData['type'] == 2) {
                            $rArray['message'] = (SettingsManager::get('redis_handler') ? $rUserMap[$rRow['user_id']] : $rRow['username']);
                        } elseif ($rData['type'] == 3) {
                            $rArray['message'] = $rData['message'];
                        }

                        $rArray['action'] = 'signal_send';
                        ApiClient::systemRequest(intval($rRow['server_id']), $rArray);
                    }
                }
            }
        }

        $this->ok();
    }

    /** action=restart_all_services — restart services on every online server. */
    public function restartAllServices(): never {
        $this->requireXhr();
        $this->gate('adv', 'servers');

        global $db, $rServers;

        foreach ($rServers as $rServer) {
            if ($rServer['server_online']) {
                $db->query("INSERT INTO `signals`(`server_id`, `custom_data`, `time`) VALUES(?, '{\"action\": \"restart_services\"}', ?);", $rServer['id'], time());
            }
        }

        $this->ok();
    }

    /** action=restart_services — restart services on the selected servers. */
    public function restartServices(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_server');

        global $db;

        foreach ($this->normalizeServerIds() as $rID) {
            $db->query("INSERT INTO `signals`(`server_id`, `custom_data`, `time`) VALUES(?, '{\"action\": \"restart_services\"}', ?);", $rID, time());
        }

        $this->ok();
    }

    /** action=reboot_server — reboot the selected servers. */
    public function rebootServer(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_server');

        global $db;

        foreach ($this->normalizeServerIds() as $rID) {
            $db->query("INSERT INTO `signals`(`server_id`, `custom_data`, `time`) VALUES(?, '{\"action\": \"reboot\"}', ?);", $rID, time());
        }

        $this->ok();
    }

    /** action=update_binaries — update binaries on the selected servers. */
    public function updateBinaries(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_server');

        global $db;

        foreach ($this->normalizeServerIds() as $rID) {
            $db->query("INSERT INTO `signals`(`server_id`, `custom_data`, `time`) VALUES(?, '{\"action\": \"update_binaries\"}', ?);", $rID, time());
        }

        $this->ok();
    }

    /** action=server_view — summary statistics for a server/proxy. */
    public function serverView(): never {
        $this->requireXhr();
        $this->gateAny(array(array('adv', 'add_server'), array('adv', 'edit_server')));

        global $db, $rServers, $rProxyServers;

        if (isset($rServers[RequestManager::get('server_id')])) {
            $rServer = $rServers[RequestManager::get('server_id')];
        } else {
            if (isset($rProxyServers[RequestManager::get('server_id')])) {
                $rServer = $rProxyServers[RequestManager::get('server_id')];
            } else {
                $this->fail();
            }
        }

        $rStats = array('open_connections' => 0, 'total_running_streams' => 0, 'online_users' => 0, 'offline_streams' => 0, 'gpu_info' => json_decode($rServer['gpu_info'], true), 'watchdog' => json_decode($rServer['watchdog_data'], true));
        $rStats['open_connections'] = ($rServer['connections'] ?: 0);
        $rStats['online_users'] = ($rServer['users'] ?: 0);
        $db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0 AND `type` = 1;', $rServer['id']);
        $rStats['total_running_streams'] = $db->get_row()['count'];
        $db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `type` = 1 AND ((`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0);', $rServer['id']);
        $rStats['offline_streams'] = $db->get_row()['count'];

        $this->ok(array('data' => $rStats, 'netspeed' => (intval($rServer['network_guaranteed_speed']) ?: 1000)));
    }

    /** action=server_stats — watchdog metrics of a server for the graphs. */
    public function serverStats(): never {
        $this->requireXhr();
        $this->gateAny(array(array('adv', 'add_server'), array('adv', 'edit_server')));

        global $rServers;
        $rID = intval(RequestManager::get('id'));

        if (isset($rServers[$rID])) {
            $rWatchdog = ProcessChecker::getWatchdog($rID);
            $rReturn = array();

            foreach ($rWatchdog as $rData) {
                $rReturn[] = array('cpu' => $rData['cpu'], 'memory' => $rData['total_mem_used_percent'], 'input' => $rData['bytes_received'], 'output' => $rData['bytes_sent'], 'date' => $rData['time']);
            }

            $this->ok(array('data' => $rReturn));
        }

        $this->fail();
    }

    /** action=rtmp_kill — kill an RTMP stream (raw node response, not JSON). */
    public function rtmpKill(): never {
        $this->requireXhr();
        $this->gate('adv', 'rtmp');

        echo ApiClient::systemRequest(intval(RequestManager::get('server')), array('action' => 'rtmp_kill', 'name' => RequestManager::get('name')));

        exit();
    }

    /** action=install_status — install progress from the server's .install file. */
    public function installStatus(): never {
        $this->requireXhr();
        $this->gateAny(array(array('adv', 'add_server'), array('adv', 'edit_server')));

        $rServers = ServerRepository::getAll(true);
        $rServerID = intval(RequestManager::get('server_id'));
        $rFilename = BIN_PATH . 'install/' . $rServerID . '.install';

        if (file_exists($rFilename)) {
            $this->ok(array('data' => trim(file_get_contents($rFilename)), 'status' => intval($rServers[$rServerID]['status'])));
        }

        $this->fail();
    }

    /** action=reinstall_server — re-run a server install from its saved params. */
    public function reinstallServer(): never {
        $this->requireXhr();
        $this->gateAny(array(array('adv', 'add_server'), array('adv', 'edit_server')));

        global $db, $rServers;
        $rServerID = intval(RequestManager::get('server_id'));

        if ($rServers[$rServerID]['server_type'] == 0) {
            $rType = 2;
        } else {
            $rType = 1;
        }

        $rFilename = BIN_PATH . 'install/' . $rServerID . '.json';

        if (file_exists($rFilename)) {
            $rParams = json_decode(file_get_contents($rFilename), true);
            $db->query('UPDATE `servers` SET `status` = 3 WHERE `id` = ?;', $rServerID);

            if (isset($rParams['http_broadcast_port'])) {
                $rCommand = PHP_BIN . ' ' . MAIN_HOME . 'console.php server:install ' . $rType . ' ' . intval($rServerID) . ' ' . intval($rParams['ssh_port']) . ' ' . escapeshellarg($rParams['root_username']) . ' ' . escapeshellarg($rParams['root_password']) . ' ' . intval($rParams['http_broadcast_port']) . ' ' . intval($rParams['https_broadcast_port']) . ' > "' . BIN_PATH . 'install/' . intval($rServerID) . '.install" 2>/dev/null &';
            } else {
                $rCommand = PHP_BIN . ' ' . MAIN_HOME . 'console.php server:install ' . $rType . ' ' . intval($rServerID) . ' ' . intval($rParams['ssh_port']) . ' ' . escapeshellarg($rParams['root_username']) . ' ' . escapeshellarg($rParams['root_password']) . ' > "' . BIN_PATH . 'install/' . intval($rServerID) . '.install" 2>/dev/null &';
            }

            shell_exec($rCommand);

            $this->ok();
        }

        $this->fail();
    }

    /** action=fpm_status — PHP-FPM status of a node (HTML). */
    public function fpmStatus(): never {
        $this->requireXhr();
        $this->gateAny(array(array('adv', 'add_server'), array('adv', 'edit_server')));

        global $rServers;
        $rData = str_replace("\n", '<br/>', ApiClient::systemRequest(RequestManager::get('server_id'), array('action' => 'fpm_status')));

        if (empty($rData)) {
            $rData = '<strong>No response from status page.</strong>';
        } else {
            $rInstances = intval($rServers[RequestManager::get('server_id')]['total_services']);

            if ($rInstances) {
                $rData .= '<br/><br/><strong>Results from 1 of ' . $rInstances . ' PHP-FPM instances</strong>';
            }
        }

        $this->ok(array('data' => $rData));
    }

    /** action=update_all_servers — signal 'update' to every online server. */
    public function updateAllServers(): never {
        $this->requireXhr();
        $this->gate('adv', 'servers');

        global $db, $rServers;

        foreach ($rServers as $rServer) {
            if ($rServer['server_online']) {
                $db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'update')));
            }
        }

        $this->ok();
    }

    /** action=update_all_binaries — signal 'update_binaries' to every online server. */
    public function updateAllBinaries(): never {
        $this->requireXhr();
        $this->gate('adv', 'servers');

        global $db, $rServers;

        foreach ($rServers as $rServer) {
            if ($rServer['server_online']) {
                $db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'update_binaries')));
            }
        }

        $this->ok();
    }

    /**
     * Normalize `server_id`: a number -> `[int]`, otherwise a JSON array of ids.
     * This idiom recurred in server(update/rollback), restart_services,
     * reboot_server and update_binaries.
     *
     * @return array<int, int|string>
     */
    private function normalizeServerIds(): array {
        if (!is_numeric(RequestManager::get('server_id'))) {
            return json_decode(RequestManager::get('server_id'), true);
        }

        return array(intval(RequestManager::get('server_id')));
    }
}
