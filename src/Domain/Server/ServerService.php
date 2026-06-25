<?php

namespace XcVm\Domain\Server;
use XcVm\Cli\CronJobs\RootSignalsCronJob;

use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\DatabaseHandler;
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
	private static $db = null;

	/**
	 * Inject the database handler (dependency injection).
	 *
	 * @param \XcVm\Core\Database\DatabaseHandler $db Database handler.
	 * @return void
	 */
	public static function setDb($db): void {
		self::$db = $db;
	}

	/**
	 * Get the injected database handler.
	 *
	 * @return object Database handler.
	 * @throws \RuntimeException If setDb() was not called first.
	 */
	private static function db(): object {
		if (self::$db === null) {
			throw new \RuntimeException(static::class . '::setDb() must be called before use.');
		}
		return self::$db;
	}
	/**
	 * Create or update a server from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process($rData) {
		$db = self::db();
		if (!Authorization::check('adv', 'edit_server')) {
			exit();
		}

		$rServer = ServerRepository::getById($rData['edit']);
		if (!$rServer) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		$rArray = QueryHelper::verifyPostTable('servers', $rData, true);
		$rPorts = array('http' => array(), 'https' => array());

		if (!isset($rData['http_broadcast_ports']) || !is_array($rData['http_broadcast_ports'])) {
			$rData['http_broadcast_ports'] = array();
		}
		if (!isset($rData['https_broadcast_ports']) || !is_array($rData['https_broadcast_ports'])) {
			$rData['https_broadcast_ports'] = array();
		}
		if (!isset($rData['rtmp_port']) || !is_numeric($rData['rtmp_port'])) {
			$rData['rtmp_port'] = $rServer['rtmp_port'] ?? 8880;
		}

		foreach ($rData['http_broadcast_ports'] as $rPort) {
			if (is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535 && !in_array($rPort, ($rPorts['http'] ?: array())) && $rPort != $rData['rtmp_port']) {
				$rPorts['http'][] = $rPort;
			}
		}
		$rPorts['http'] = array_unique($rPorts['http']);
		unset($rData['http_broadcast_ports']);

		foreach ($rData['https_broadcast_ports'] as $rPort) {
			if (is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535 && !in_array($rPort, ($rPorts['http'] ?: array())) && !in_array($rPort, ($rPorts['https'] ?: array())) && $rPort != $rData['rtmp_port']) {
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

		foreach (array('enable_gzip', 'timeshift_only', 'enable_https', 'random_ip', 'enable_geoip', 'enable_isp', 'enabled', 'enable_proxy') as $rKey) {
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
			$rArray['geoip_countries'] = array();
			foreach ($rData['geoip_countries'] as $rCountry) {
				$rArray['geoip_countries'][] = $rCountry;
			}
		} else {
			$rArray['geoip_countries'] = array();
		}

		if (isset($rData['isp_names'])) {
			$rArray['isp_names'] = array();
			foreach ($rData['isp_names'] as $rISP) {
				$rArray['isp_names'][] = strtolower(trim(preg_replace('/[^A-Za-z0-9 ]/', '', $rISP)));
			}
		} else {
			$rArray['isp_names'] = array();
		}

		if (isset($rData['domain_name'])) {
			$rArray['domain_name'] = implode(',', $rData['domain_name']);
		} else {
			$rArray['domain_name'] = '';
		}

		if (strlen($rData['server_ip']) == 0 || !filter_var($rData['server_ip'], FILTER_VALIDATE_IP)) {
			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		}
		if (0 < strlen($rData['private_ip']) && !filter_var($rData['private_ip'], FILTER_VALIDATE_IP)) {
			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		}

		$rArray['total_services'] = $rData['total_services'];
		$rPrepare = QueryHelper::prepareArray($rArray);
		$rPrepare['data'][] = $rData['edit'];
		$rQuery = 'UPDATE `servers` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';

		if (!$db->query($rQuery, ...$rPrepare['data'])) {
			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}

		$rInsertID = $rData['edit'];
		$rPorts = array('http' => array(), 'https' => array());
		foreach (array_merge(array(intval($rArray['http_broadcast_port'])), explode(',', $rArray['http_ports_add'])) as $rPort) {
			if (is_numeric($rPort) && 0 < $rPort && $rPort <= 65535) {
				$rPorts['http'][] = intval($rPort);
			}
		}
		foreach (array_merge(array(intval($rArray['https_broadcast_port'])), explode(',', $rArray['https_ports_add'])) as $rPort) {
			if (is_numeric($rPort) && 0 < $rPort && $rPort <= 65535) {
				$rPorts['https'][] = intval($rPort);
			}
		}
		ServerService::changePort($rInsertID, 0, $rPorts['http'], false);
		ServerService::changePort($rInsertID, 1, $rPorts['https'], false);
		ServerService::changePort($rInsertID, 2, array($rArray['rtmp_port']), false);
		ServerService::setServices($rInsertID, intval($rArray['total_services']), true);

		if (!empty($rArray['governor'])) {
			ServerService::setGovernor($rInsertID, $rArray['governor']);
		}
		if (!empty($rArray['sysctl'])) {
			ServerService::setSysctl($rInsertID, $rArray['sysctl']);
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
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rInsertID, time(), json_encode(array('action' => 'disable_ramdisk')));
		} else if (!$rDisableRamdisk && !$rMounted) {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rInsertID, time(), json_encode(array('action' => 'enable_ramdisk')));
		}

		return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
	}

	/**
	 * Create or update a proxy server from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function processProxy($rData) {
		$db = self::db();
		if (!Authorization::check('adv', 'edit_server')) {
			exit();
		}

		$rArray = AdminHelpers::overwriteData(ServerRepository::getById($rData['edit']), $rData);
		foreach (array('enable_https', 'random_ip', 'enable_geoip', 'enabled') as $rKey) {
			$rArray[$rKey] = isset($rData[$rKey]);
		}

		if (isset($rData['geoip_countries'])) {
			$rArray['geoip_countries'] = array();
			foreach ($rData['geoip_countries'] as $rCountry) {
				$rArray['geoip_countries'][] = $rCountry;
			}
		} else {
			$rArray['geoip_countries'] = array();
		}

		if (isset($rData['domain_name'])) {
			$rArray['domain_name'] = implode(',', $rData['domain_name']);
		} else {
			$rArray['domain_name'] = '';
		}

		if (strlen($rData['server_ip']) == 0 || !filter_var($rData['server_ip'], FILTER_VALIDATE_IP)) {
			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		}
		if (QueryHelper::checkExists('servers', 'server_ip', $rData['server_ip'], 'id', $rArray['id'])) {
			return array('status' => STATUS_EXISTS_IP, 'data' => $rData);
		}

		$rArray['server_type'] = 1;
		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `servers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			if (file_exists(CACHE_TMP_PATH . 'servers')) {
				unlink(CACHE_TMP_PATH . 'servers');
			}
			if (file_exists(CACHE_TMP_PATH . 'proxy_servers')) {
				unlink(CACHE_TMP_PATH . 'proxy_servers');
			}
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	/**
	 * Trigger installation/provisioning of a server over SSH.
	 *
	 * @param array $rData         Install parameters (target, credentials, type).
	 * @param array $rServers      Existing servers configuration.
	 * @param array $rProxyServers Existing proxy servers configuration.
	 * @return array Result status payload.
	 */
	public static function install($rData, $rServers, $rProxyServers) {
		$db = self::db();
		if (!Authorization::check('adv', 'add_server')) {
			exit();
		}

		$rParentIDs = array();
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
				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}

			$db->query('UPDATE `servers` SET `status` = 3, `parent_id` = ? WHERE `id` = ?;', '[' . implode(',', $rParentIDs) . ']', $rServer['id']);
			if ($rData['type'] == 1) {
				$rCommand = PHP_BIN . ' ' . MAIN_HOME . 'console.php server:install ' . intval($rData['type']) . ' ' . intval($rServer['id']) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' ' . intval($rData['http_broadcast_port']) . ' ' . intval($rData['https_broadcast_port']) . ' ' . intval($rUpdateSysctl) . ' ' . intval($rPrivateIP) . ' "' . json_encode($rParentIDs) . '" > "' . BIN_PATH . 'install/' . intval($rServer['id']) . '.install" 2>/dev/null &';
			} else {
				$rCommand = PHP_BIN . ' ' . MAIN_HOME . 'console.php server:install ' . intval($rData['type']) . ' ' . intval($rServer['id']) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' 80 443 ' . intval($rUpdateSysctl) . ' > "' . BIN_PATH . 'install/' . intval($rServer['id']) . '.install" 2>/dev/null &';
			}
			shell_exec($rCommand);
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rServer['id']));
		}

		$rArray = QueryHelper::verifyPostTable('servers', $rData);
		$rArray['status'] = 3;
		unset($rArray['id']);

		if (strlen($rArray['server_ip']) == 0 || !filter_var($rArray['server_ip'], FILTER_VALIDATE_IP)) {
			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
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
			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}

		$rInsertID = $db->last_insert_id();
		if ($rArray['server_type'] == 0) {
			BackupService::grantPrivileges($rArray['server_ip']);
		}

		if ($rData['type'] == 1) {
			$rCommand = PHP_BIN . ' ' . MAIN_HOME . 'console.php server:install ' . intval($rData['type']) . ' ' . intval($rInsertID) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' ' . intval($rData['http_broadcast_port']) . ' ' . intval($rData['https_broadcast_port']) . ' ' . intval($rUpdateSysctl) . ' ' . intval($rPrivateIP) . ' "' . json_encode($rParentIDs) . '" > "' . BIN_PATH . 'install/' . intval($rInsertID) . '.install" 2>/dev/null &';
		} else {
			$rCommand = PHP_BIN . ' ' . MAIN_HOME . 'console.php server:install ' . intval($rData['type']) . ' ' . intval($rInsertID) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' 80 443 ' . intval($rUpdateSysctl) . ' > "' . BIN_PATH . 'install/' . intval($rInsertID) . '.install" 2>/dev/null &';
		}

		shell_exec($rCommand);
		return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
	}

	/**
	 * Persist the display order of servers.
	 *
	 * @param array $rData Ordered server ids.
	 * @return array ['status' => STATUS_* constant].
	 */
	public static function reorder($rData) {
		$db = self::db();
		$rPostServers = json_decode($rData['server_order'], true);
		if (count($rPostServers) > 0) {
			foreach ($rPostServers as $rOrder => $rPostServer) {
				$db->query('UPDATE `servers` SET `order` = ? WHERE `id` = ?;', intval($rOrder) + 1, $rPostServer['id']);
			}
		}

		return array('status' => STATUS_SUCCESS);
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
	public static function changePort($rServerID, $rType, $rPorts, $rReload = false) {
		$db = self::db();
		$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_port', 'type' => intval($rType), 'ports' => $rPorts, 'reload' => $rReload)));
	}

	/**
	 * Set the number of service workers on a server.
	 *
	 * @param int  $rServerID    Server id.
	 * @param int  $rNumServices Number of service workers.
	 * @param bool $rReload      Reload services after the change.
	 * @return mixed Result.
	 */
	public static function setServices($rServerID, $rNumServices, $rReload = true) {
		$db = self::db();
		$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_services', 'count' => intval($rNumServices), 'reload' => $rReload)));
	}

	/**
	 * Set the CPU frequency governor on a server.
	 *
	 * @param int    $rServerID Server id.
	 * @param string $rGovernor Governor name (e.g. performance).
	 * @return mixed Result.
	 */
	public static function setGovernor($rServerID, $rGovernor) {
		$db = self::db();
		$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_governor', 'data' => $rGovernor)));
	}

	/**
	 * Apply sysctl settings on a server.
	 *
	 * @param int   $rServerID Server id.
	 * @param mixed $rSysCtl   Sysctl key/values to apply.
	 * @return mixed Result.
	 */
	public static function setSysctl($rServerID, $rSysCtl) {
		$db = self::db();
		$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_sysctl', 'data' => $rSysCtl)));
	}

	/**
	 * Restore missing UI/asset images.
	 *
	 * @return mixed Result.
	 */
	public static function restoreImages() {
		$db = self::db();
		global $rServers;
		foreach (array_keys($rServers) as $rServerID) {
			if (!$rServers[$rServerID]['server_online']) {
			} else {
				ApiClient::systemRequest($rServerID, array('action' => 'restore_images'));
			}
		}

		return true;
	}

	/**
	 * Kill the running Plex sync process.
	 *
	 * @return mixed Result.
	 */
	public static function killPlexSync() {
		$db = self::db();
		$db->query("SELECT DISTINCT(`server_id`) AS `server_id` FROM `watch_folders` WHERE `active` = 1 AND `type` = 'plex';");

		global $rServers;
		foreach ($db->get_rows() as $rRow) {
			if (!$rServers[$rRow['server_id']]['server_online']) {
			} else {
				ApiClient::systemRequest($rRow['server_id'], array('action' => 'kill_plex'));
			}
		}

		return true;
	}
}
