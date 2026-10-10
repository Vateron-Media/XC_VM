<?php

namespace XcVm\Domain\Server;

use XcVm\Core\Backup\BackupService;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Core\Cluster\DataPlane;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaBoot;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Server\ServerSavedEvent;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ServerRepository — server repository
 *
 * @package XC_VM_Domain_Server
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServerRepository {
	use DatabaseAware;

	/**
	 * Fetch all servers (cached unless forced).
	 *
	 * On a node whose replica owns the servers cache (CONFIG on, and an apply
	 * built it from the `servers` and `node` sections: ReplicaApply::owns),
	 * every caller gets that cache however old it is, even when forced, never
	 * MAIN's database. Should the cache be gone, it is rebuilt from the
	 * replica on disk (a refused replica hands it back to the database). The
	 * database's rows are never written over a cache the replica owns, so a
	 * database copy never stands in for the replica's with nothing to refresh
	 * it. Until an apply built it, the database as before; but a process
	 * booted from the replica (ReplicaBoot) gets the servers cache however
	 * old, or nothing, never MAIN's database. On a node in mode 1
	 * (ReplicaBoot::hybrid) such a process reads the database as before once
	 * the replica no longer owns the cache, on the lazy handle's first use,
	 * counted.
	 *
	 * @param bool $rForce Bypass the cache and re-read from the database.
	 * @return array Server rows keyed by id.
	 */
	public static function getAll(bool $rForce = false) {
		global $rSettings;
		$db = self::db();
		if (!$rSettings) {
			$rSettings = SettingsRepository::loaded();
		}
		$rReplica = ReplicaApply::owns(ReplicaSections::SERVERS);
		if (!$rForce || $rReplica) {
			$rCache = $rReplica ? FileCache::getCache('servers') : FileCache::getCache('servers', 10);
			if (empty($rCache) && $rReplica) {
				ReplicaApply::servers(true, (int) SERVER_ID);
				$rCache = ReplicaApply::owns(ReplicaSections::SERVERS) ? FileCache::getCache('servers') : false;
			}
			if (!empty($rCache)) {
				return $rCache;
			}
		}
		if (ReplicaBoot::active() && !ReplicaBoot::hybrid()) {
			return ReplicaBoot::cached('servers');
		}

		if (empty($_SERVER['REQUEST_SCHEME'])) {
			$_SERVER['REQUEST_SCHEME'] = 'http';
		}

		$db->query('SELECT * FROM `servers`');
		$rServers = [];
		$rOnlineStatus = [1];

		foreach ($db->get_rows() ?: [] as $rRow) {
			$rRow = self::decorate($rRow, $rSettings);

			if ($rRow['server_type'] == 1) {
				$rLastCheckTime = 180;
			} else {
				$rLastCheckTime = 90;
			}

			$rRow['server_online'] = $rRow['enabled'] && in_array($rRow['status'], $rOnlineStatus) && time() - $rRow['last_check_ago'] <= $rLastCheckTime || SERVER_ID == $rRow['id'];
			// A node whose agent reports its telemetry is judged by MAIN's
			// liveness loop instead (offline after 30 s of silence, not 90 s).
			$rRow['cluster_health'] = ClusterHealth::state(intval($rRow['id']));
			if ($rRow['cluster_health'] !== null && SERVER_ID != $rRow['id']) {
				$rRow['server_online'] = $rRow['enabled'] && in_array($rRow['status'], $rOnlineStatus) && !in_array($rRow['cluster_health'], ClusterHealth::NO_ROUTING, true);
			}
			if (!isset($rRow['order'])) {
				$rRow['order'] = 0;
			}
			// One pid per live PHP-FPM worker; only cron:users needs it and it
			// reads the column itself. Keep it out of the per-request cache.
			unset($rRow['php_pids']);
			$rServers[intval($rRow['id'])] = $rRow;
		}

		// Asked again: an apply may have built the replica's cache meanwhile.
		if (!ReplicaApply::owns(ReplicaSections::SERVERS)) {
			FileCache::setCache('servers', $rServers);
		}

		return $rServers;
	}

	/**
	 * What getAll() derives from a `servers` row besides liveness: its URLs
	 * (the api_url ones with this node's live_streaming_pass), the decoded
	 * GeoIP, ISP and parent lists, and whether it serves plain HTTP. The node
	 * replica builds its servers cache through it too (ReplicaApply).
	 *
	 * @param array<string, mixed> $rRow A `servers` row.
	 * @param array<string, mixed> $rSettings Settings (live_streaming_pass).
	 * @return array<string, mixed>
	 */
	public static function decorate(array $rRow, array $rSettings): array {
		if (empty($rRow['domain_name'])) {
			$rURL = escapeshellcmd((string) $rRow['server_ip']);
		} else {
			$rURL = str_replace(['http://', '/', 'https://'], '', escapeshellcmd(explode(',', $rRow['domain_name'])[0]));
		}

		if ($rRow['enable_https'] == 1) {
			$rProtocol = 'https';
		} else {
			$rProtocol = 'http';
		}

		$rPort = ($rProtocol == 'http' ? intval($rRow['http_broadcast_port']) : intval($rRow['https_broadcast_port']));
		$rRow['server_protocol'] = $rProtocol;
		$rRow['request_port'] = $rPort;
		$rRow['site_url'] = $rProtocol . '://' . $rURL . ':' . $rPort . '/';
		$rRow['http_url'] = 'http://' . $rURL . ':' . intval($rRow['http_broadcast_port']) . '/';
		$rRow['https_url'] = 'https://' . $rURL . ':' . intval($rRow['https_broadcast_port']) . '/';
		$rRow['rtmp_server'] = 'rtmp://' . $rURL . ':' . intval($rRow['rtmp_port']) . '/live/';
		$rRow['domains'] = ['protocol' => $rProtocol, 'port' => $rPort, 'urls' => array_filter(array_map('escapeshellcmd', explode(',', $rRow['domain_name'] ?? '')))];
		$rRow['rtmp_mport_url'] = 'http://127.0.0.1:31210/';
		$rRow['api_url_ip'] = 'http://' . escapeshellcmd((string) $rRow['server_ip']) . ':' . intval($rRow['http_broadcast_port']) . '/api?password=' . urlencode((string) ($rSettings['live_streaming_pass'] ?? ''));
		$rRow['api_url'] = $rRow['api_url_ip'];
		$rRow['site_url_ip'] = $rProtocol . '://' . escapeshellcmd((string) $rRow['server_ip']) . ':' . $rPort . '/';
		$rRow['private_url_ip'] = (!empty($rRow['private_ip']) ? 'http://' . escapeshellcmd($rRow['private_ip']) . ':' . intval($rRow['http_broadcast_port']) . '/' : null);
		$rRow['public_url_ip'] = 'http://' . escapeshellcmd((string) $rRow['server_ip']) . ':' . intval($rRow['http_broadcast_port']) . '/';
		$rRow['geoip_countries'] = (empty($rRow['geoip_countries']) ? [] : json_decode($rRow['geoip_countries'], true));
		$rRow['isp_names'] = (empty($rRow['isp_names']) ? [] : json_decode($rRow['isp_names'], true));

		if (is_numeric($rRow['parent_id'])) {
			$rRow['parent_id'] = [intval($rRow['parent_id'])];
		} else {
			$decoded = json_decode($rRow['parent_id'] ?? '', true);
			$rRow['parent_id'] = is_array($decoded) ? array_map('intval', $decoded) : [];
		}

		if ($rRow['enable_https'] == 2) {
			$rRow['allow_http'] = false;
		} else {
			$rRow['allow_http'] = true;
		}

		$rRow['watchdog'] = json_decode((string) ($rRow['watchdog_data'] ?? ''), true);
		return $rRow;
	}

	/**
	 * Fetch a lightweight list of all servers.
	 *
	 * @return array Reduced server rows.
	 */
	public static function getAllSimple() {
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT * FROM `servers` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rRow['server_online'] = in_array($rRow['status'], [1, 3]) && time() - $rRow['last_check_ago'] <= 90 || $rRow['is_main'];
				// Worker pid list, left out as in getAll().
				unset($rRow['php_pids']);
				$rReturn[$rRow['id']] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch streaming servers visible to the user, filtered by state.
	 *
	 * @param array|null $rPermissions Effective permissions (null pre-auth, e.g. on the login page).
	 * @param string     $type         State filter (e.g. 'online').
	 * @return array Streaming server rows.
	 */
	public static function getStreamingSimple(?array $rPermissions = null, string $type = 'online') {
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT * FROM `servers` WHERE `server_type` = 0 ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				if (isset($rPermissions['is_reseller']) && $rPermissions['is_reseller']) {
					$rRow['server_name'] = 'Server #' . ($rRow['id'] ?? 'unknown');
				}

				$rRow['server_online'] = in_array($rRow['status'], [1, 3]) && time() - $rRow['last_check_ago'] <= 90 || $rRow['is_main'];
				if (!isset($rRow['order'])) {
					$rRow['order'] = 0;
				}
				// Worker pid list, left out as in getAll().
				unset($rRow['php_pids']);
				if ($rRow['server_online'] || $type == 'all') {
					$rReturn[$rRow['id']] = $rRow;
				}
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch proxy servers visible to the user.
	 *
	 * @param array|null $rPermissions Effective permissions (null pre-auth, e.g. on the login page).
	 * @param bool       $rOnline      Restrict to online proxies.
	 * @return array Proxy server rows.
	 */
	public static function getProxySimple(?array $rPermissions = null, bool $rOnline = false) {
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT * FROM `servers` WHERE `server_type` = 1 ORDER BY `id` ASC;');

		if (0 < $db->num_rows()) {
			foreach ($db->get_rows() as $rRow) {
				if (isset($rPermissions['is_reseller']) && $rPermissions['is_reseller']) {
					$rRow['server_name'] = 'Proxy #' . $rRow['id'];
				}

				$rRow['server_online'] = in_array($rRow['status'], [1, 3]) && time() - $rRow['last_check_ago'] <= 90 || $rRow['is_main'];
				// Worker pid list, left out as in getAll().
				unset($rRow['php_pids']);
				if ($rRow['server_online'] != 0 || !$rOnline) {
					$rReturn[$rRow['id']] = $rRow;
				}
			}
		}

		return $rReturn;
	}

	/**
	 * Get free disk space for a server.
	 *
	 * @param int $rServerID Server id.
	 * @return mixed Free-space information.
	 */
	public static function getFreeSpace(int $rServerID) {
		$rReturn = [];
		$rLines = json_decode(NodeRpc::request($rServerID, ['action' => 'get_free_space']), true);

		if (!is_array($rLines)) {
			return $rReturn;
		}

		if ($rLines !== []) {
			array_shift($rLines);
		}

		foreach ($rLines as $rLine) {
			$rSplit = explode(' ', preg_replace('!\s+!', ' ', trim($rLine)));
			if ($rSplit[0] !== '' && strpos($rSplit[5], 'xc_vm') !== false || $rSplit[5] == '/') {
				$rReturn[] = ['filesystem' => $rSplit[0], 'size' => $rSplit[1], 'used' => $rSplit[2], 'avail' => $rSplit[3], 'percentage' => $rSplit[4], 'mount' => implode(' ', array_slice($rSplit, 5, count($rSplit) - 5))];
			}
		}

		return $rReturn;
	}

	/**
	 * Get ramdisk usage/stream info for a server.
	 *
	 * @param int $rServerID Server id.
	 * @return mixed Ramdisk information.
	 */
	public static function getStreamsRamdisk(int $rServerID) {
		$response = NodeRpc::request($rServerID, ['action' => 'streams_ramdisk']);
		$rReturn = json_decode($response, true);

		if (!is_array($rReturn)) {
			return [];
		}

		if (empty($rReturn['result'])) {
			return [];
		}

		return ($rReturn['streams'] ?? []);
	}

	/**
	 * Kill a process on a server by PID.
	 *
	 * @param int $rServerID Server id.
	 * @param int $rPID      Process id to kill.
	 * @return mixed Result of the kill request.
	 */
	public static function killPID(int $rServerID, int $rPID) {
		NodeRpc::request($rServerID, ['action' => 'kill_pid', 'pid' => $rPID]);
	}

	/**
	 * Get RTMP statistics from a server.
	 *
	 * @param int $rServerID Server id.
	 * @return mixed RTMP stats.
	 */
	public static function getRTMPStats(int $rServerID) {
		return json_decode(NodeRpc::request($rServerID, ['action' => 'rtmp_stats']), true);
	}

	/**
	 * Check/probe a source file on a server.
	 *
	 * @param array  $rServers  Servers configuration.
	 * @param mixed  $rFFProbe  ffprobe path/config.
	 * @param int    $rServerID Server id.
	 * @param string $rFilename Source file/path.
	 * @return mixed Probe result.
	 */
	public static function checkSource(array $rServers, mixed $rFFProbe, int $rServerID, string $rFilename) {
		$rAPI = self::fileUrl($rServers, $rServerID, $rFilename);
		$rCommand = 'timeout 10 ' . $rFFProbe . ' -user_agent "Mozilla/5.0" -show_streams -v quiet "' . $rAPI . '" -of json';
		return json_decode(shell_exec($rCommand), true);
	}

	/**
	 * The URL MAIN reads another server's file with: through its own agent
	 * while MAIN's data-plane client is on (DataPlane::fileUrl, ADR 0004,
	 * Phase 9's eighth increment), else `getFile` of that server's `/api`
	 * with its IP, as before.
	 *
	 * @param array<int, array<string, mixed>> $rServers
	 */
	private static function fileUrl(array $rServers, int $rServerID, string $rPath): string {
		$rLegacy = ($rServers[$rServerID]['api_url_ip'] ?? '') . '&action=getFile&filename=' . urlencode($rPath);
		if (!DataPlane::on()) {
			return $rLegacy;
		}
		$rUrl = DataPlane::fileUrl($rServers, $rServerID, $rPath);
		// DataPlane's own legacy URL is the api_url (the server's domain); keep the IP one.
		return str_starts_with($rUrl, 'http://' . DataPlane::HOST . ':' . DataPlane::PORT . '/') ? $rUrl : $rLegacy;
	}

	/**
	 * Get the SSL/certbot log for a server.
	 *
	 * @param int $rServerID Server id.
	 * @return mixed SSL log contents.
	 */
	public static function getSSLLog(int $rServerID) {
		global $rServers;
		$rServer = $rServers[intval($rServerID)] ?? null;
		if (!$rServer || empty($rServer['api_url_ip'])) {
			return null;
		}
		$rAPI = self::fileUrl($rServers, intval($rServerID), BIN_PATH . 'certbot/logs/xc_vm.log');
		$rResponse = @file_get_contents($rAPI);
		if ($rResponse === false) {
			return null;
		}
		return json_decode($rResponse, true);
	}

	/**
	 * Clear temporary files on a server.
	 *
	 * @param int $rServerID Server id.
	 * @return mixed Result of the request.
	 */
	public static function freeTemp(int $rServerID) {
		NodeRpc::request($rServerID, ['action' => 'free_temp']);
	}

	/**
	 * Clear stream temp/ramdisk data on a server.
	 *
	 * @param int $rServerID Server id.
	 * @return mixed Result of the request.
	 */
	public static function freeStreams(int $rServerID) {
		NodeRpc::request($rServerID, ['action' => 'free_streams']);
	}

	/**
	 * Probe a source URL via a server (ffprobe).
	 *
	 * @param int         $rServerID  Server id.
	 * @param string      $rURL       Source URL.
	 * @param string|null $rUserAgent Optional user agent.
	 * @param mixed       $rProxy     Optional proxy.
	 * @param string|null $rCookies   Optional cookies.
	 * @param mixed       $rHeaders   Optional extra headers.
	 * @return mixed Probe result.
	 */
	public static function probeSource(int $rServerID, string $rURL, ?string $rUserAgent = null, mixed $rProxy = null, ?string $rCookies = null, mixed $rHeaders = null) {
		return json_decode(NodeRpc::request($rServerID, ['action' => 'probe', 'url' => $rURL, 'user_agent' => $rUserAgent, 'http_proxy' => $rProxy, 'cookies' => $rCookies, 'headers' => $rHeaders], 30), true);
	}

	/**
	 * Delete a server, optionally reassigning its streams to another server.
	 *
	 * @param int      $rID          Server id.
	 * @param int|null $rReplaceWith Replacement server id for reassignment.
	 * @return bool True on success.
	 */
	public static function deleteById(int $rID, ?int $rReplaceWith = null) {
		global $rSettings;
		$db = self::db();
		$rServer = self::getById($rID);

		if (!$rServer || $rServer['is_main']) {
			return false;
		}

		// Its streams: the replacement's replica takes them, the replica of any
		// server that relayed them from it follows its new parent.
		$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_id` = ?;', $rID);
		$rStreamIDs = array_map('intval', array_column($db->get_rows() ?: [], 'stream_id'));
		if ($rReplaceWith) {
			$db->query('UPDATE `streams_servers` SET `server_id` = ? WHERE `server_id` = ?;', $rReplaceWith, $rID);
			if (!$rSettings['redis_handler']) {
				$db->query('UPDATE `lines_live` SET `server_id` = ? WHERE `server_id` = ?;', $rReplaceWith, $rID);
			}
			$db->query('UPDATE `lines_activity` SET `server_id` = ? WHERE `server_id` = ?;', $rReplaceWith, $rID);
		} else {
			$db->query('DELETE FROM `streams_servers` WHERE `server_id` = ?;', $rID);
			if (!$rSettings['redis_handler']) {
				$db->query('DELETE FROM `lines_live` WHERE `server_id` = ?;', $rID);
			}
			$db->query('UPDATE `lines_activity` SET `server_id` = 0 WHERE `server_id` = ?;', $rID);
		}

		$db->query('UPDATE `servers` SET `parent_id` = NULL, `enabled` = 0 WHERE `server_type` = 1 AND `parent_id` = ?;', $rID);
		$db->query('DELETE FROM `servers_stats` WHERE `server_id` = ?;', $rID);
		$db->query('DELETE FROM `servers` WHERE `id` = ?;', $rID);
		EventDispatcher::dispatch(new ServerSavedEvent([$rID]));
		EventDispatcher::dispatch(new StreamsChangedEvent($rStreamIDs));

		if ($rServer['server_type'] == 0) {
			BackupService::revokePrivileges($rServer['server_ip']);
		}
		if (class_exists(NodeRegistry::class)) {
			NodeRegistry::serverDeleted($rID);
		}

		return true;
	}

	/**
	 * Get the list of allowed reseller domains (cached).
	 *
	 * @param bool $rForce Bypass the cache.
	 * @return array Allowed domains.
	 */
	public static function getAllowedDomains(bool $rForce = false) {
		$db = self::db();
		if (!$rForce) {
			$rCache = FileCache::getCache('allowed_domains', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}
		$db->query('SELECT `server_ip`, `private_ip`, `domain_name`, `enabled` FROM `servers` WHERE `enabled` = 1;');
		$rServers = $db->get_rows() ?: [];
		$db->query("SELECT DISTINCT `reseller_dns` FROM `users` WHERE `status` = 1 AND `reseller_dns` <> '' ORDER BY `reseller_dns`;");
		$rDomains = self::allowedDomains($rServers, array_column($db->get_rows() ?: [], 'reseller_dns'));
		FileCache::setCache('allowed_domains', $rDomains);
		return $rDomains;
	}

	/**
	 * verify_host's list from the servers' rows and the active resellers'
	 * DNS: localhost, and every enabled server's names and addresses. Pure:
	 * a node whose replica owns the servers (ReplicaApply) builds it from its
	 * `servers` section as cron:cache builds it from the database.
	 *
	 * @param iterable<array<string, mixed>> $rServers
	 * @param list<string> $rResellerDns
	 * @return list<string>
	 */
	public static function allowedDomains(iterable $rServers, array $rResellerDns): array {
		$rDomains = ['127.0.0.1', 'localhost'];
		foreach ($rServers as $rRow) {
			if (empty($rRow['enabled'])) {
				continue;
			}
			foreach (explode(',', (string) ($rRow['domain_name'] ?? '')) as $rDomain) {
				$rDomains[] = $rDomain;
			}
			$rDomains[] = (string) ($rRow['server_ip'] ?? '');
			$rDomains[] = (string) ($rRow['private_ip'] ?? '');
		}
		return array_values(array_filter(array_unique(array_merge($rDomains, $rResellerDns))));
	}

	/**
	 * Get the list of allowed IPs (cached).
	 *
	 * @param bool $rForce Bypass the cache.
	 * @return array Allowed IPs.
	 */
	public static function getAllowedIPs(bool $rForce = false) {
		global $rServers, $rSettings;
		if (!$rForce) {
			$rCache = FileCache::getCache('allowed_ips', 60);
			if ($rCache !== false) {
				return $rCache;
			}
		}
		$rIPs = ['127.0.0.1'];
		$rServerAddr = ($_SERVER['SERVER_ADDR'] ?? null);
		if (!empty($rServerAddr)) {
			$rIPs[] = $rServerAddr;
		} elseif (isset($rServers[SERVER_ID]['server_ip']) && !empty($rServers[SERVER_ID]['server_ip'])) {
			$rIPs[] = $rServers[SERVER_ID]['server_ip'];
		}
		foreach ($rServers as $rServerInfo) {
			if (!empty($rServerInfo['whitelist_ips'])) {
				$rIPs = array_merge($rIPs, json_decode($rServerInfo['whitelist_ips'], true));
			}
			$rIPs[] = $rServerInfo['server_ip'];
			if ($rServerInfo['private_ip']) {
				$rIPs[] = $rServerInfo['private_ip'];
			}
			foreach (explode(',', $rServerInfo['domain_name'] ?? '') as $rIP) {
				if (filter_var($rIP, FILTER_VALIDATE_IP)) {
					$rIPs[] = $rIP;
				}
			}
		}
		if (!empty($rSettings['allowed_ips_admin'])) {
			$rIPs = array_merge($rIPs, explode(',', $rSettings['allowed_ips_admin']));
		}
		FileCache::setCache('allowed_ips', $rIPs);
		return array_unique($rIPs);
	}

	/**
	 * Get RTMP statistics for the local server.
	 *
	 * @return mixed Local RTMP stats.
	 */
	public static function getLocalRTMPStats() {
		global $rServers;
		$rURL = $rServers[SERVER_ID]['rtmp_mport_url'] . 'stat';
		$rContext = stream_context_create(['http' => ['timeout' => 1]]);
		$rXML = file_get_contents($rURL, false, $rContext);
		return json_decode(json_encode(simplexml_load_string($rXML, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
	}

	/**
	 * Get the public base URL of a server.
	 *
	 * @param int|null    $rServerID      Server id, or null for the current server.
	 * @param string|null $rForceProtocol Force http/https.
	 * @return string Public URL.
	 */
	public static function getPublicURL(?int $rServerID = null, ?string $rForceProtocol = null) {
		global $rSettings, $rServers;
		$rOriginatorID = null;
		if (!isset($rServerID)) {
			$rServerID = SERVER_ID;
		}
		if ($rForceProtocol) {
			$rProtocol = $rForceProtocol;
		} else {
			if (isset($_SERVER['SERVER_PORT']) && $rSettings['keep_protocol']) {
				$rProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http');
			} else {
				// ?? 'http': $rServerID may not be present in the map (handled by
				// the !$rServers[$rServerID] check below) — avoid the undefined-key
				// / offset-on-null warning while it is still being read here.
				$rProtocol = $rServers[$rServerID]['server_protocol'] ?? 'http';
			}
		}
		if ($rServers[$rServerID]) {
			if ($rServers[$rServerID]['enable_proxy']) {
				$rProxyIDs = array_keys(ConnectionTracker::getProxies($rServerID));
				if (count($rProxyIDs) == 0) {
					$rProxyIDs = array_keys(ConnectionTracker::getProxies($rServerID, false));
				}
				if (count($rProxyIDs) != 0) {
					$rOriginatorID = $rServerID;
					$rServerID = $rProxyIDs[array_rand($rProxyIDs)];
				} else {
					return '';
				}
			}
			// The constant is HOST: `host` is defined nowhere, and the requested host was never kept.
			$rHost = (defined('HOST') ? HOST : null);
			if ($rHost && in_array(strtolower($rHost), array_map('strtolower', $rServers[$rServerID]['domains']['urls']))) {
				$rDomain = $rHost;
			} else {
				$rDomain = (empty($rServers[$rServerID]['domain_name']) ? $rServers[$rServerID]['server_ip'] : explode(',', $rServers[$rServerID]['domain_name'])[0]);
			}
			$rServerURL = $rProtocol . '://' . $rDomain . ':' . $rServers[$rServerID][$rProtocol . '_broadcast_port'] . '/';
			if ($rServers[$rServerID]['server_type'] == 1 && $rOriginatorID && $rServers[$rOriginatorID]['is_main'] == 0) {
				$rServerURL .= ProxyRoute::segment((int) $rServerID, (int) $rOriginatorID, $rServers) . '/';
			}
			return $rServerURL;
		}
		return '';
	}

	/**
	 * Fetch a single server by id. The full row, php_pids included:
	 * ServerService writes it back whole with REPLACE.
	 *
	 * @param int $rID Server id.
	 * @return array|null The server row, or null if not found.
	 */
	public static function getById(int $rID) {
		$db = self::db();
		$db->query('SELECT * FROM `servers` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return null;
		}

		return $db->get_row();
	}
}
