<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Updates\UpdateChannels;
use XcVm\Domain\Cluster\PlacementAdvice;
use XcVm\Domain\Cluster\RollingUpdate;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\InstallCredentials;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Streaming\Health\ProcessChecker;

/**
 * Admin-ajax controller for the "Servers/Ops" group: rtmp_ip,
 * rollback_versions, server, proxy, fingerprint, restart_all_services,
 * restart_services, reboot_server, update_binaries, server_view, server_stats,
 * rtmp_kill, install_status, reinstall_server, fpm_status, update_all_servers,
 * update_all_binaries, rolling_update_start, rolling_update_cancel,
 * rolling_update_status, placement_advice.
 *
 * Note: rtmp_kill echoes the raw {@see NodeRpc::request()} response
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
		$rVersions = [];

		try {
			$rGit = UpdateChannels::mainReleases();
			$rGit->setTimeout(15);
			$rVersions = $rGit->getPreviousVersions($rBaseVersion, 5);
		} catch (\Throwable) {
			$rVersions = [];
		}

		$this->ok(['current' => $rBaseVersion, 'versions' => $rVersions]);
	}

	/** action=server — server operations (dispatches on sub). */
	public function server(): never {
		$this->requireXhr();
		$this->gate('adv', 'edit_server');

		global $db, $rServers;
		$rSub = RequestManager::get('sub');

		if ($rSub == 'delete') {
			// Look the server up in the full list (getAll), not the online-filtered
			// $rServers global — otherwise an offline/failed server can never be deleted.
			$rAllServers = ServerRepository::getAll();
			$rDeleteId = RequestManager::get('server_id');
			if (isset($rAllServers[$rDeleteId]) && $rAllServers[$rDeleteId]['is_main'] == 0) {
				ServerRepository::deleteById($rDeleteId);
				$this->ok();
			}

			$this->fail();
		}

		if ($rSub == 'update') {
			$rSent = true;
			foreach ($this->normalizeServerIds() as $rID) {
				$rSent = NodeActions::update(intval($rID), $db) && $rSent;
			}

			$this->sent($rSent);
		}

		if ($rSub == 'rollback') {
			$rVersion = trim((string) RequestManager::get('version'));

			if (!preg_match('/^\d+\.\d+\.\d+$/', $rVersion) || version_compare($rVersion, XC_VM_VERSION, '>=')) {
				$this->fail(['error' => 'invalid_version']);
			}

			$rSent = true;
			foreach ($this->normalizeServerIds() as $rID) {
				$rSent = NodeActions::rollback(intval($rID), $rVersion, $db) && $rSent;
			}

			$this->sent($rSent);
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

		if (in_array($rSub, ['restart', 'start', 'stop'], true)) {
			// 'restart' additionally requires an already-running monitored stream.
			$rOnDemand = ($rSub == 'restart') ? ' AND `monitor_pid` > 0 AND `pid` > 0 AND `stream_status` = 0' : '';
			$rSignalSub = ($rSub == 'stop') ? 'stop' : 'start';
			$rStreamIDs = [];
			$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_id` = ? AND `on_demand` = 0' . $rOnDemand . ';', RequestManager::get('server_id'));

			if (0 < $db->num_rows()) {
				foreach ($db->get_rows() as $rRow) {
					$rStreamIDs[] = intval($rRow['stream_id']);
				}
			}

			if (0 < count($rStreamIDs)) {
				ApiClient::request(['action' => 'stream', 'sub' => $rSignalSub, 'stream_ids' => array_values($rStreamIDs), 'servers' => [intval(RequestManager::get('server_id'))]]);
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
			$db->query('UPDATE `servers` SET `enabled` = 0 WHERE `id` = ? AND `is_main` = 0;', RequestManager::get('server_id'));
			$this->ok();
		}

		if ($rSub == 'update') {
			NodeActions::update(intval(RequestManager::get('server_id')), $db);
			$this->ok();
		}

		if ($rSub == 'kill') {
			if (SettingsManager::get('redis_handler')) {
				$rServerID = intval(RequestManager::get('server_id'));

				foreach ((ServerRepository::getAll()[$rServerID]['parent_id'] ?? []) as $rParentID) {
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
		$rActiveServers = [];

		foreach ($rServers as $rServer) {
			$rServerError = ((360 < time() - $rServer['last_check_ago'] || $rServer['status'] == 2) && $rServer['is_main'] == 0 && $rServer['status'] != 3);

			if ($rServer['status'] == 1 && !$rServerError) {
				$rActiveServers[] = $rServer['id'];
			}
		}

		if (0 < $rData['id'] && 0 < $rData['font_size'] && (string) $rData['font_color'] !== '' && (string) $rData['xy_offset'] !== '' && ((string) $rData['message'] !== '' || $rData['type'] < 3)) {
			if (SettingsManager::get('redis_handler')) {
				if (isset($rData['user'])) {
					$rRows = ConnectionTracker::getRedisConnections($rData['id'], null, null, true, false, false);
				} else {
					$rRows = ConnectionTracker::getRedisConnections(null, null, $rData['id'], true, false, false);
				}

				$rUserMap = $rUserIDs = [];

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
						$rArray = ['font_size' => $rData['font_size'], 'font_color' => $rData['font_color'], 'xy_offset' => $rData['xy_offset'], 'message' => '', 'uuid' => $rRow['uuid']];

						if ($rData['type'] == 1) {
							$rArray['message'] = $rRow['uuid'];
						} elseif ($rData['type'] == 2) {
							$rArray['message'] = (SettingsManager::get('redis_handler') ? $rUserMap[$rRow['user_id']] : $rRow['username']);
						} elseif ($rData['type'] == 3) {
							$rArray['message'] = $rData['message'];
						}

						$rArray['action'] = 'signal_send';
						NodeRpc::request(intval($rRow['server_id']), $rArray);
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

		$rSkipped = [];
		foreach ($rServers as $rServer) {
			if ($rServer['server_online'] && !NodeActions::restartServices(intval($rServer['id']), $db)) {
				$rSkipped[] = (string) $rServer['server_name'];
			}
		}

		$this->okSkipping($rSkipped);
	}

	/** action=restart_services — restart services on the selected servers. */
	public function restartServices(): never {
		$this->requireXhr();
		$this->gate('adv', 'edit_server');

		global $db;

		$rSent = true;
		foreach ($this->normalizeServerIds() as $rID) {
			$rSent = NodeActions::restartServices(intval($rID), $db) && $rSent;
		}

		$this->sent($rSent);
	}

	/** action=reboot_server — reboot the selected servers. */
	public function rebootServer(): never {
		$this->requireXhr();
		$this->gate('adv', 'edit_server');

		global $db;

		$rSent = true;
		foreach ($this->normalizeServerIds() as $rID) {
			$rSent = NodeActions::reboot(intval($rID), $db) && $rSent;
		}

		$this->sent($rSent);
	}

	/** action=update_binaries — update binaries on the selected servers. */
	public function updateBinaries(): never {
		$this->requireXhr();
		$this->gate('adv', 'edit_server');

		global $db;

		$rSent = true;
		foreach ($this->normalizeServerIds() as $rID) {
			$rSent = NodeActions::updateBinaries(intval($rID), $db) && $rSent;
		}

		$this->sent($rSent);
	}

	/** action=server_view — summary statistics for a server/proxy. */
	public function serverView(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'add_server'], ['adv', 'edit_server']]);

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

		$rStats = ['open_connections' => 0, 'total_running_streams' => 0, 'online_users' => 0, 'offline_streams' => 0, 'gpu_info' => json_decode($rServer['gpu_info'], true), 'watchdog' => json_decode($rServer['watchdog_data'], true)];
		$rStats['open_connections'] = ($rServer['connections'] ?: 0);
		$rStats['online_users'] = ($rServer['users'] ?: 0);
		$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0 AND `type` = 1;', $rServer['id']);
		$rStats['total_running_streams'] = $db->get_row()['count'];
		$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `type` = 1 AND ((`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0);', $rServer['id']);
		$rStats['offline_streams'] = $db->get_row()['count'];

		$this->ok(['data' => $rStats, 'netspeed' => (intval($rServer['network_guaranteed_speed']) ?: 1000)]);
	}

	/** action=server_stats — watchdog metrics of a server for the graphs. */
	public function serverStats(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'add_server'], ['adv', 'edit_server']]);

		global $rServers;
		$rID = intval(RequestManager::get('id'));

		if (isset($rServers[$rID])) {
			$rWatchdog = ProcessChecker::getWatchdog($rID);
			$rReturn = [];

			foreach ($rWatchdog as $rData) {
				$rReturn[] = ['cpu' => $rData['cpu'], 'memory' => $rData['total_mem_used_percent'], 'input' => $rData['bytes_received'], 'output' => $rData['bytes_sent'], 'date' => $rData['time']];
			}

			$this->ok(['data' => $rReturn]);
		}

		$this->fail();
	}

	/**
	 * action=rtmp_kill — kill an RTMP stream. Echoes the node's raw JSON response
	 * ({"result":…}) when present; an empty/absent body (offline node, or a
	 * self-request that yields nothing) falls back to {"result":false} so the
	 * page is never blank and the frontend's data.result check stays valid.
	 */
	public function rtmpKill(): never {
		$this->requireXhr();
		$this->gate('adv', 'rtmp');

		$rResult = NodeRpc::request(intval(RequestManager::get('server')), ['action' => 'rtmp_kill', 'name' => RequestManager::get('name')]);

		if (empty($rResult)) {
			$this->fail();
		}

		echo $rResult;
		exit();
	}

	/** action=install_status — install progress from the server's .install file. */
	public function installStatus(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'add_server'], ['adv', 'edit_server']]);

		$rServers = ServerRepository::getAll(true);
		$rServerID = intval(RequestManager::get('server_id'));
		$rFilename = BIN_PATH . 'install/' . $rServerID . '.install';

		if (file_exists($rFilename)) {
			$this->ok(['data' => trim(file_get_contents($rFilename)), 'status' => intval($rServers[$rServerID]['status'])]);
		}

		$this->fail();
	}

	/**
	 * action=reinstall_server — re-run a server install from its saved params.
	 * The saved params no longer hold the SSH password, so the request must
	 * carry it (root_password); an optional expected_hostkey overrides the
	 * stored host key for a rebuilt node, and forget_hostkey has it forgotten.
	 */
	public function reinstallServer(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'add_server'], ['adv', 'edit_server']]);

		global $db, $rServers;
		$rServerID = intval(RequestManager::get('server_id'));

		if ($rServers[$rServerID]['server_type'] == 0) {
			$rType = 2;
		} else {
			$rType = 1;
		}

		$rFilename = BIN_PATH . 'install/' . $rServerID . '.json';

		$rPassword = (string) RequestManager::get('root_password');

		if (file_exists($rFilename) && $rPassword !== '') {
			$rParams = json_decode(file_get_contents($rFilename), true);
			$db->query('UPDATE `servers` SET `status` = 3 WHERE `id` = ?;', $rServerID);

			$rTail = isset($rParams['http_broadcast_port'])
				? [(string) intval($rParams['http_broadcast_port']), (string) intval($rParams['https_broadcast_port'])]
				: [];
			$rCommand = InstallCredentials::command($rType, intval($rServerID), intval($rParams['ssh_port']), (string) $rParams['root_username'], $rPassword, $rTail, (string) RequestManager::get('expected_hostkey'), !empty(RequestManager::get('forget_hostkey')));

			shell_exec($rCommand);

			$this->ok();
		}

		$this->fail();
	}

	/** action=fpm_status — PHP-FPM status of a node (HTML). */
	public function fpmStatus(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'add_server'], ['adv', 'edit_server']]);

		global $rServers;
		$rData = str_replace("\n", '<br/>', NodeRpc::request(RequestManager::get('server_id'), ['action' => 'fpm_status']));

		if (empty($rData)) {
			$rData = '<strong>No response from status page.</strong>';
		} else {
			$rInstances = intval($rServers[RequestManager::get('server_id')]['total_services']);

			if ($rInstances) {
				$rData .= '<br/><br/><strong>Results from 1 of ' . $rInstances . ' PHP-FPM instances</strong>';
			}
		}

		$this->ok(['data' => $rData]);
	}

	/** action=update_all_servers — signal 'update' to every online server. */
	public function updateAllServers(): never {
		$this->requireXhr();
		$this->gate('adv', 'servers');

		global $db, $rServers;

		$rSkipped = [];
		foreach ($rServers as $rServer) {
			if ($rServer['server_online'] && !NodeActions::update(intval($rServer['id']), $db)) {
				$rSkipped[] = (string) $rServer['server_name'];
			}
		}

		$this->okSkipping($rSkipped);
	}

	/** action=rolling_update_start — update the load balancers one at a time (RollingUpdate). */
	public function rollingUpdateStart(): never {
		$this->requireXhr();
		$this->gate('adv', 'servers');
		$this->json(['result' => RollingUpdate::start()]);
	}

	/** action=rolling_update_cancel — stop the rolling update before its next look. */
	public function rollingUpdateCancel(): never {
		$this->requireXhr();
		$this->gate('adv', 'servers');
		RollingUpdate::cancel();
		$this->ok();
	}

	/** action=rolling_update_status — the rolling update's progress, for the Servers page. */
	public function rollingUpdateStatus(): never {
		$this->requireXhr();
		$this->gate('adv', 'servers');
		$rState = RollingUpdate::state();
		$this->json(['result' => true, 'state' => $rState, 'running' => RollingUpdate::running($rState)]);
	}

	/** action=placement_advice — busy servers and where their busiest streams could also run (advice only). */
	public function placementAdvice(): never {
		$this->requireXhr();
		$this->gate('adv', 'servers');
		$this->json(['result' => true] + PlacementAdvice::current());
	}

	/** action=update_all_binaries — signal 'update_binaries' to every online server. */
	public function updateAllBinaries(): never {
		$this->requireXhr();
		$this->gate('adv', 'servers');

		global $db, $rServers;

		$rSkipped = [];
		foreach ($rServers as $rServer) {
			if ($rServer['server_online'] && !NodeActions::updateBinaries(intval($rServer['id']), $db)) {
				$rSkipped[] = (string) $rServer['server_name'];
			}
		}

		$this->okSkipping($rSkipped);
	}

	/**
	 * A bulk action's answer: done, naming the servers that took none of it
	 * (`not_sent`), which the page shows with what to do.
	 *
	 * @param list<string> $rSkipped
	 */
	private function okSkipping(array $rSkipped): never {
		$this->ok($rSkipped === [] ? [] : ['not_sent' => $rSkipped]);
	}

	/**
	 * A root action's answer: `not_sent` when a node took none of it (a node
	 * in mode 2 that takes no root command, ClusterRoute::root).
	 */
	private function sent(bool $rSent): never {
		$rSent ? $this->ok() : $this->fail(['error' => 'not_sent']);
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

		return [intval(RequestManager::get('server_id'))];
	}
}
