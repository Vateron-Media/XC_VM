<?php

namespace XcVm\Domain\Server;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Server\ServerSavedEvent;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Cluster\ClusterEndpoint;
use XcVm\Domain\Cluster\DbCredentials;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ServerService — server service
 *
 * @package XC_VM_Domain_Server
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServerService {
	use DatabaseAware;

	/**
	 * Create or update a server from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process(array $rData) {
		$db = self::db();
		if (!Authorization::check('adv', 'edit_server')) {
			exit();
		}

		$rServer = ServerRepository::getById($rData['edit']);
		if (!$rServer) {
			return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
		}

		$rArray = QueryHelper::verifyPostTable('servers', $rData, true);
		$rPorts = ['http' => [], 'https' => []];

		if (!isset($rData['http_broadcast_ports']) || !is_array($rData['http_broadcast_ports'])) {
			$rData['http_broadcast_ports'] = [];
		}
		if (!isset($rData['https_broadcast_ports']) || !is_array($rData['https_broadcast_ports'])) {
			$rData['https_broadcast_ports'] = [];
		}
		if (!isset($rData['rtmp_port']) || !is_numeric($rData['rtmp_port'])) {
			$rData['rtmp_port'] = $rServer['rtmp_port'] ?? 8880;
		}

		foreach ($rData['http_broadcast_ports'] as $rPort) {
			if (is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535 && !in_array($rPort, ($rPorts['http'])) && $rPort != $rData['rtmp_port']) {
				$rPorts['http'][] = $rPort;
			}
		}
		$rPorts['http'] = array_unique($rPorts['http']);
		unset($rData['http_broadcast_ports']);

		foreach ($rData['https_broadcast_ports'] as $rPort) {
			if (is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535 && !in_array($rPort, ($rPorts['http'])) && !in_array($rPort, ($rPorts['https'])) && $rPort != $rData['rtmp_port']) {
				$rPorts['https'][] = $rPort;
			}
		}
		$rPorts['https'] = array_unique($rPorts['https']);
		unset($rData['https_broadcast_ports']);
		$rArray['http_broadcast_port'] = null;
		$rArray['http_ports_add'] = null;

		if (count($rPorts['http']) > 0) {
			$rArray['http_broadcast_port'] = $rPorts['http'][0];
			if (1 < count($rPorts['http'])) {
				$rArray['http_ports_add'] = implode(',', array_slice($rPorts['http'], 1, count($rPorts['http']) - 1));
			}
		}

		$rArray['https_broadcast_port'] = null;
		$rArray['https_ports_add'] = null;
		if (count($rPorts['https']) > 0) {
			$rArray['https_broadcast_port'] = $rPorts['https'][0];
			if (1 < count($rPorts['https'])) {
				$rArray['https_ports_add'] = implode(',', array_slice($rPorts['https'], 1, count($rPorts['https']) - 1));
			}
		}

		foreach (['enable_gzip', 'timeshift_only', 'enable_https', 'random_ip', 'enable_geoip', 'enable_isp', 'enabled', 'enable_proxy'] as $rKey) {
			$rArray[$rKey] = isset($rData[$rKey]) ? 1 : 0;
		}

		// Persist the ramdisk choice. RootSignalsCronJob reconciles the actual
		// tmpfs mount to this column, so without saving it the toggle is reverted
		// on the next cron tick. "Disable Ramdisk" checked => use_disk = 1.
		$rArray['use_disk'] = !empty($rData['disable_ramdisk']) ? 1 : 0;

		if ($rServer['is_main']) {
			$rArray['enabled'] = 1;
		}

		if (isset($rData['geoip_countries'])) {
			$rArray['geoip_countries'] = [];
			foreach ($rData['geoip_countries'] as $rCountry) {
				$rArray['geoip_countries'][] = $rCountry;
			}
		} else {
			$rArray['geoip_countries'] = [];
		}

		if (isset($rData['isp_names'])) {
			$rArray['isp_names'] = [];
			foreach ($rData['isp_names'] as $rISP) {
				$rArray['isp_names'][] = strtolower(trim(preg_replace('/[^A-Za-z0-9 ]/', '', $rISP)));
			}
		} else {
			$rArray['isp_names'] = [];
		}

		if (isset($rData['domain_name'])) {
			if (!self::domainNamesValid($rData['domain_name'], (string) $rServer['domain_name'])) {
				return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
			}
			$rArray['domain_name'] = implode(',', $rData['domain_name']);
		} else {
			$rArray['domain_name'] = '';
		}

		if (strlen($rData['server_ip']) == 0 || !filter_var($rData['server_ip'], FILTER_VALIDATE_IP)) {
			return ['status' => STATUS_INVALID_IP, 'data' => $rData];
		}
		if ((string) $rData['private_ip'] !== '' && !filter_var($rData['private_ip'], FILTER_VALIDATE_IP)) {
			return ['status' => STATUS_INVALID_IP, 'data' => $rData];
		}
		// This machine's own row: a new port another program already holds here
		// is refused. nginx's reload would fail to bind it and keep running the
		// old configuration, while the row (and, for MAIN, the nodes'
		// announcement) named the new one.
		if ((int) $rServer['id'] === (int) SERVER_ID && self::portsTaken($rServer, array_merge($rPorts['http'], $rPorts['https'], [$rData['rtmp_port']])) !== []) {
			return ['status' => STATUS_PORT_IN_USE, 'data' => $rData];
		}

		$rArray['total_services'] = $rData['total_services'];
		$rPrepare = QueryHelper::prepareArray($rArray);
		$rPrepare['data'][] = $rData['edit'];
		$rQuery = 'UPDATE `servers` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';

		if (!$db->query($rQuery, ...$rPrepare['data'])) {
			return ['status' => STATUS_FAILURE, 'data' => $rData];
		}

		$rInsertID = $rData['edit'];
		EventDispatcher::dispatch(new ServerSavedEvent([(int) $rInsertID]));
		self::announceMainEndpoints($rServer, $rArray);
		$rPorts = ['http' => [], 'https' => []];
		foreach (array_merge([intval($rArray['http_broadcast_port'])], explode(',', $rArray['http_ports_add'])) as $rPort) {
			if (is_numeric($rPort) && 0 < $rPort && $rPort <= 65535) {
				$rPorts['http'][] = intval($rPort);
			}
		}
		foreach (array_merge([intval($rArray['https_broadcast_port'])], explode(',', $rArray['https_ports_add'])) as $rPort) {
			if (is_numeric($rPort) && 0 < $rPort && $rPort <= 65535) {
				$rPorts['https'][] = intval($rPort);
			}
		}
		self::changePort($rInsertID, 0, $rPorts['http'], false);
		self::changePort($rInsertID, 1, $rPorts['https'], false);
		self::changePort($rInsertID, 2, [$rArray['rtmp_port']], false);
		self::setServices($rInsertID, intval($rArray['total_services']), true);

		if (!empty($rArray['governor'])) {
			self::setGovernor($rInsertID, $rArray['governor']);
		}
		if (!empty($rArray['sysctl'])) {
			self::setSysctl($rInsertID, $rArray['sysctl']);
		}
		if (file_exists(CACHE_TMP_PATH . 'servers')) {
			unlink(CACHE_TMP_PATH . 'servers');
		}

		$rFS = ServerRepository::getFreeSpace($rInsertID);
		$rMounted = false;
		foreach ($rFS as $rMount) {
			if ($rMount['mount'] == rtrim(STREAMS_PATH, '/')) {
				$rMounted = true;
				break;
			}
		}

		$rDisableRamdisk = !empty($rData['disable_ramdisk']);
		if ($rDisableRamdisk && $rMounted) {
			NodeActions::setRamdisk(intval($rInsertID), false, $db);
		} elseif (!$rDisableRamdisk && !$rMounted) {
			NodeActions::setRamdisk(intval($rInsertID), true, $db);
		}

		return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
	}

	/**
	 * Whether a server's domain list holds only what the form adds to it:
	 * addresses, and names made of letters, digits, hyphens and underscores
	 * in labels joined by dots. An entry the server already has is kept as it
	 * is (a migrated row may hold a name written as a URL). The list is stored
	 * joined by commas and goes into URLs, the allowed hosts and certbot's
	 * request.
	 *
	 * @param mixed  $rNames  The posted `domain_name` list.
	 * @param string $rStored The server's `domain_name` as stored.
	 */
	public static function domainNamesValid(mixed $rNames, string $rStored = ''): bool {
		if (!is_array($rNames)) {
			return false;
		}
		$rKept = array_filter(explode(',', $rStored));
		foreach ($rNames as $rName) {
			if (!is_string($rName) || !(in_array($rName, $rKept, true) || filter_var($rName, FILTER_VALIDATE_IP) || preg_match('/^[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)*$/iD', $rName))) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The ports of $rPorts this server does not listen on yet (its row's
	 * HTTP, HTTPS and RTMP ports) that something on this machine already
	 * accepts connections on.
	 *
	 * @param array<string, mixed> $rServer The row as stored.
	 * @param array<int|string>    $rPorts  The ports the save would use.
	 * @param callable|null        $rListening fn(int $port): bool (tests)
	 * @return list<int>
	 */
	public static function portsTaken(array $rServer, array $rPorts, ?callable $rListening = null): array {
		$rOwn = [];
		foreach (['http_broadcast_port', 'https_broadcast_port', 'rtmp_port', 'http_ports_add', 'https_ports_add'] as $rKey) {
			foreach (explode(',', (string) ($rServer[$rKey] ?? '')) as $rPort) {
				if (is_numeric($rPort)) {
					$rOwn[] = (int) $rPort;
				}
			}
		}
		$rListening ??= static function (int $rPort): bool {
			$rSocket = @fsockopen('127.0.0.1', $rPort, $rErrno, $rError, 0.5);
			if ($rSocket === false) {
				return false;
			}
			fclose($rSocket);
			return true;
		};
		$rTaken = [];
		foreach (array_unique(array_map('intval', array_filter($rPorts, 'is_numeric'))) as $rPort) {
			if ($rPort > 0 && !in_array($rPort, $rOwn, true) && $rListening($rPort)) {
				$rTaken[] = $rPort;
			}
		}
		return $rTaken;
	}

	/**
	 * MAIN's endpoints moved on its server page (its HTTP or HTTPS port, its
	 * server_ip or private_ip): announce it to the cluster nodes, and keep
	 * the old port or URL a while (ClusterEndpoint::recordMainChange()).
	 * process() runs it once the row is stored and before the ports are
	 * applied, so nginx gets a kept old port with the new ones. Returns
	 * whether it was announced. Domain\Cluster is not in the LB build.
	 *
	 * @param array<string, mixed> $rServer The row before the save.
	 * @param array<string, mixed> $rArray The columns the save stored.
	 */
	public static function announceMainEndpoints(array $rServer, array $rArray): bool {
		if (empty($rServer['is_main']) || !class_exists(ClusterEndpoint::class)) {
			return false;
		}
		return ClusterEndpoint::recordMainChange($rServer, $rArray + $rServer, SettingsManager::getAll());
	}

	/**
	 * Create or update a proxy server from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function processProxy(array $rData) {
		$db = self::db();
		if (!Authorization::check('adv', 'edit_server')) {
			exit();
		}

		$rServer = ServerRepository::getById($rData['edit']);
		$rArray = AdminHelpers::overwriteData($rServer, $rData);
		foreach (['enable_https', 'random_ip', 'enable_geoip', 'enabled'] as $rKey) {
			$rArray[$rKey] = isset($rData[$rKey]);
		}

		if (isset($rData['geoip_countries'])) {
			$rArray['geoip_countries'] = [];
			foreach ($rData['geoip_countries'] as $rCountry) {
				$rArray['geoip_countries'][] = $rCountry;
			}
		} else {
			$rArray['geoip_countries'] = [];
		}

		if (isset($rData['domain_name'])) {
			if (!self::domainNamesValid($rData['domain_name'], (string) $rServer['domain_name'])) {
				return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
			}
			$rArray['domain_name'] = implode(',', $rData['domain_name']);
		} else {
			$rArray['domain_name'] = '';
		}

		if (strlen($rData['server_ip']) == 0 || !filter_var($rData['server_ip'], FILTER_VALIDATE_IP)) {
			return ['status' => STATUS_INVALID_IP, 'data' => $rData];
		}
		if (QueryHelper::checkExists('servers', 'server_ip', $rData['server_ip'], 'id', $rArray['id'])) {
			return ['status' => STATUS_EXISTS_IP, 'data' => $rData];
		}

		$rArray['server_type'] = 1;
		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `servers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			EventDispatcher::dispatch(new ServerSavedEvent([(int) $rInsertID]));
			if (file_exists(CACHE_TMP_PATH . 'servers')) {
				unlink(CACHE_TMP_PATH . 'servers');
			}
			if (file_exists(CACHE_TMP_PATH . 'proxy_servers')) {
				unlink(CACHE_TMP_PATH . 'proxy_servers');
			}
			return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
		}

		return ['status' => STATUS_FAILURE, 'data' => $rData];
	}

	/**
	 * Trigger installation/provisioning of a server over SSH.
	 *
	 * @param array $rData         Install parameters (target, credentials, type).
	 * @param array $rServers      Existing servers configuration.
	 * @param array $rProxyServers Existing proxy servers configuration.
	 * @return array Result status payload.
	 */
	public static function install(array $rData, array $rServers, array $rProxyServers) {
		$db = self::db();
		if (!Authorization::check('adv', 'add_server')) {
			exit();
		}

		$rParentIDs = [];
		$rUpdateSysctl = isset($rData['update_sysctl']) ? 1 : 0;
		$rPrivateIP = isset($rData['use_private_ip']) ? 1 : 0;

		if ($rData['type'] == 1) {
			foreach (json_decode($rData['parent_id'], true) as $rServerID) {
				if ($rServers[$rServerID]['server_type'] == 0) {
					$rParentIDs[] = intval($rServerID);
				}
			}
		}

		if (isset($rData['edit'])) {
			if ($rData['type'] == 1) {
				$rServer = $rProxyServers[$rData['edit']];
			} else {
				$rServer = $rServers[$rData['edit']];
			}
			if (!$rServer) {
				return ['status' => STATUS_FAILURE, 'data' => $rData];
			}

			// Said before the row says the server is being installed.
			if ($rData['type'] != 1 && self::modeTwoInstallRefused(intval($rServer['id']))) {
				return ['status' => STATUS_FAILURE, 'data' => $rData, 'message' => 'cluster_mode_redis_handler'];
			}

			$db->query('UPDATE `servers` SET `status` = 3, `parent_id` = ? WHERE `id` = ?;', '[' . implode(',', $rParentIDs) . ']', $rServer['id']);
			if ($rData['type'] == 1) {
				$rCommand = InstallCredentials::command(intval($rData['type']), intval($rServer['id']), intval($rData['ssh_port']), (string) $rData['root_username'], (string) $rData['root_password'], [(string) intval($rData['http_broadcast_port']), (string) intval($rData['https_broadcast_port']), (string) intval($rUpdateSysctl), (string) intval($rPrivateIP), escapeshellarg(json_encode($rParentIDs))], (string) ($rData['expected_hostkey'] ?? ''), !empty($rData['forget_hostkey']));
			} else {
				$rCommand = InstallCredentials::command(intval($rData['type']), intval($rServer['id']), intval($rData['ssh_port']), (string) $rData['root_username'], (string) $rData['root_password'], ['80', '443', (string) intval($rUpdateSysctl)], (string) ($rData['expected_hostkey'] ?? ''), !empty($rData['forget_hostkey']));
			}
			shell_exec($rCommand);
			return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rServer['id']]];
		}

		$rArray = QueryHelper::verifyPostTable('servers', $rData);
		$rArray['status'] = 3;
		unset($rArray['id']);

		if (strlen($rArray['server_ip']) == 0 || !filter_var($rArray['server_ip'], FILTER_VALIDATE_IP)) {
			return ['status' => STATUS_INVALID_IP, 'data' => $rData];
		}
		// A server that already has a row is reinstalled, not added again: a
		// second row for the same node (or for MAIN) left two servers on one
		// machine, and deleting the stale one revoked the live node's database grant.
		if (QueryHelper::checkExists('servers', 'server_ip', $rArray['server_ip'])) {
			return ['status' => STATUS_EXISTS_IP, 'data' => $rData];
		}
		// No row is added for an install that would be refused.
		if ($rData['type'] != 1 && self::modeTwoInstallRefused()) {
			return ['status' => STATUS_FAILURE, 'data' => $rData, 'message' => 'cluster_mode_redis_handler'];
		}

		if ($rData['type'] == 1) {
			$rArray['server_type'] = 1;
			$rArray['parent_id'] = '[' . implode(',', $rParentIDs) . ']';
		} else {
			$rArray['server_type'] = 0;
		}

		$rArray['network_interface'] = 'auto';
		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'INSERT INTO `servers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if (!$db->query($rQuery, ...$rPrepare['data'])) {
			return ['status' => STATUS_FAILURE, 'data' => $rData];
		}

		$rInsertID = $db->last_insert_id();
		EventDispatcher::dispatch(new ServerSavedEvent([(int) $rInsertID]));
		// An LB installed in API mode never gets MAIN's database (plan, section
		// 10): no grant, and a credential-free config.enc (LbInstallFlow).
		if ($rArray['server_type'] == 0 && !ClusterSettings::newNodesInApiMode(SettingsManager::getAll())) {
			BackupService::grantPrivileges($rArray['server_ip']);
		}

		if ($rData['type'] == 1) {
			$rCommand = InstallCredentials::command(intval($rData['type']), intval($rInsertID), intval($rData['ssh_port']), (string) $rData['root_username'], (string) $rData['root_password'], [(string) intval($rData['http_broadcast_port']), (string) intval($rData['https_broadcast_port']), (string) intval($rUpdateSysctl), (string) intval($rPrivateIP), escapeshellarg(json_encode($rParentIDs))], (string) ($rData['expected_hostkey'] ?? ''));
		} else {
			$rCommand = InstallCredentials::command(intval($rData['type']), intval($rInsertID), intval($rData['ssh_port']), (string) $rData['root_username'], (string) $rData['root_password'], ['80', '443', (string) intval($rUpdateSysctl)], (string) ($rData['expected_hostkey'] ?? ''));
		}

		shell_exec($rCommand);
		return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
	}

	/**
	 * Would `server:install` refuse this load balancer because it installs in
	 * cluster mode 2 while the Redis connection handler is on (the command's
	 * own check, LbInstallFlow::installsInApiMode)? The panel asks before it
	 * marks the server as being installed: a server marked so is out of
	 * rotation, and after the command's refusal only an install that succeeds
	 * brings it back.
	 *
	 * @param int $rServerID The load balancer; 0 for one that has no row yet.
	 */
	public static function modeTwoInstallRefused(int $rServerID = 0): bool {
		$rSettings = SettingsManager::getAll();
		if (empty($rSettings['redis_handler'])) {
			return false;
		}

		return ClusterSettings::newNodesInApiMode($rSettings) || (!empty($rSettings['cluster_api_enabled']) && class_exists(DbCredentials::class) && DbCredentials::credentialFree($rServerID));
	}

	/**
	 * Persist the display order of servers.
	 *
	 * @param array $rData Ordered server ids.
	 * @return array ['status' => STATUS_* constant].
	 */
	public static function reorder(array $rData) {
		$db = self::db();
		$rPostServers = json_decode($rData['server_order'], true);
		if (count($rPostServers) > 0) {
			foreach ($rPostServers as $rOrder => $rPostServer) {
				$db->query('UPDATE `servers` SET `order` = ? WHERE `id` = ?;', intval($rOrder) + 1, $rPostServer['id']);
			}
			EventDispatcher::dispatch(new ServerSavedEvent(array_map(static fn($rPostServer): int => (int) ($rPostServer['id'] ?? 0), $rPostServers)));
		}

		return ['status' => STATUS_SUCCESS];
	}

	/**
	 * Change a server's listening ports.
	 *
	 * @param int   $rServerID Server id.
	 * @param int   $rType     Port type code (0=http, 1=https, 2=rtmp, ...).
	 * @param mixed $rPorts    New port value(s).
	 * @param bool  $rReload   Reload services after the change.
	 * @return mixed Result.
	 */
	public static function changePort(int $rServerID, int $rType, mixed $rPorts, bool $rReload = false) {
		$db = self::db();
		NodeActions::setPorts(intval($rServerID), intval($rType), $rPorts, $rReload, $db);
	}

	/**
	 * Set the number of service workers on a server.
	 *
	 * @param int  $rServerID    Server id.
	 * @param int  $rNumServices Number of service workers.
	 * @param bool $rReload      Reload services after the change.
	 * @return mixed Result.
	 */
	public static function setServices(int $rServerID, int $rNumServices, bool $rReload = true) {
		$db = self::db();
		NodeActions::setServices(intval($rServerID), intval($rNumServices), $rReload, $db);
	}

	/**
	 * Set the CPU frequency governor on a server.
	 *
	 * @param int    $rServerID Server id.
	 * @param string $rGovernor Governor name (e.g. performance).
	 * @return mixed Result.
	 */
	public static function setGovernor(int $rServerID, string $rGovernor) {
		$db = self::db();
		NodeActions::setGovernor(intval($rServerID), $rGovernor, $db);
	}

	/**
	 * Apply sysctl settings on a server.
	 *
	 * @param int   $rServerID Server id.
	 * @param mixed $rSysCtl   Sysctl key/values to apply.
	 * @return mixed Result.
	 */
	public static function setSysctl(int $rServerID, mixed $rSysCtl) {
		$db = self::db();
		NodeActions::setSysctl(intval($rServerID), $rSysCtl, $db);
	}

	/**
	 * Restore missing UI/asset images.
	 *
	 * @return mixed Result.
	 */
	public static function restoreImages() {
		global $rServers;
		foreach (array_keys($rServers) as $rServerID) {
			if ($rServers[$rServerID]['server_online']) {
				NodeRpc::request($rServerID, ['action' => 'restore_images']);
			}
		}

		return true;
	}
}
