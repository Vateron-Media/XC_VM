<?php

namespace XcVm\Core\Http;

use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Server\ServerRepository;

/**
 * ApiClient — internal API communication
 *
 * @package XC_VM_Core_Http
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ApiClient {
	/** Pages of a `stat` listing read at most (about 60 KB each: some 400,000 files); past it, no listing. */
	private const MAX_LISTING_PAGES = 500;

	/**
	 * POST a request to the local admin API endpoint.
	 *
	 * @param array $rData    Request payload (api_pass is injected if configured).
	 * @param int   $rTimeout Connect/read timeout in seconds.
	 * @return string|bool Response body, or false on failure.
	 */
	public static function request(array $rData, int $rTimeout = 5) {
		ini_set('default_socket_timeout', $rTimeout);
		$rAPI = 'http://127.0.0.1:' . intval(ServerRepository::getAll()[SERVER_ID]['http_broadcast_port']) . '/admin/api';

		if (!empty(SettingsManager::get('api_pass'))) {
			$rData['api_pass'] = SettingsManager::get('api_pass');
		}

		$rPost = http_build_query($rData);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $rAPI);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $rPost);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $rTimeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $rTimeout);

		return curl_exec($ch);
	}

	/**
	 * POST a request to a remote server's system API (when the server is online).
	 *
	 * @param int   $rServerID Target server id.
	 * @param array $rData     Request payload (live-streaming password injected).
	 * @param int   $rTimeout  Connect/read timeout in seconds.
	 * @return string|null Response body, or null if the server is offline/unknown.
	 */
	public static function systemRequest(int $rServerID, array $rData, int $rTimeout = 5) {
		ini_set('default_socket_timeout', $rTimeout);
		global $rServers, $rSettings;
		if (!is_array($rServers) || !isset($rServers[$rServerID])) {
			return null;
		}
		if ($rServers[$rServerID]['server_online']) {
			$rAPI = 'http://' . $rServers[intval($rServerID)]['server_ip'] . ':' . $rServers[intval($rServerID)]['http_broadcast_port'] . '/api';
			$rData['password'] = $rSettings['live_streaming_pass'];
			$rPost = http_build_query($rData);
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $rAPI);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $rPost);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $rTimeout);
			curl_setopt($ch, CURLOPT_TIMEOUT, $rTimeout);

			return curl_exec($ch);
		}
		return null;
	}

	/**
	 * Fire a request to several servers concurrently (fire-and-forget).
	 *
	 * @param int[] $rServerIDs Target server ids (offline servers are skipped).
	 * @param array $rData      Request payload sent to each server.
	 * @return array ['result' => true].
	 */
	public static function asyncRequest(array $rServerIDs, array $rData) {
		$rURLs = [];
		global $rServers;

		foreach ($rServerIDs as $rServerID) {
			if ($rServers[$rServerID]['server_online']) {
				$rURLs[$rServerID] = ['url' => $rServers[$rServerID]['api_url'], 'postdata' => $rData];
			}
		}
		CurlClient::getMultiCURL($rURLs);

		return ['result' => true];
	}

	/**
	 * The sizes of files on a remote server, by its listing asked for one file
	 * at a time (`scandir_recursive` with `stat` and `size`): path => bytes,
	 * null for a file that is not there. Null as a whole when nothing can be
	 * told: the server did not answer, refused a path (it lists only under its
	 * Scan Roots), or predates `size`.
	 *
	 * @param string[] $rPaths
	 * @return array<string, int|null>|null
	 */
	public static function fileSizes(int $rServerID, array $rPaths): ?array {
		$rSizes = [];
		foreach ($rPaths as $rPath) {
			// Encoded once more: the server urldecode()s what it is sent, and a
			// file name may hold a `+` or a `%`.
			$rAnswer = json_decode((string) NodeRpc::request($rServerID, ['action' => 'scandir_recursive', 'dir' => rawurlencode($rPath), 'allowed' => '', 'stat' => 1, 'size' => 1]), true);
			if (!is_array($rAnswer) || !is_array($rAnswer['sizes'] ?? null)) {
				return null;
			}
			$rSizes[$rPath] = isset($rAnswer['sizes'][$rPath]) ? (int) $rAnswer['sizes'][$rPath] : null;
		}
		return $rSizes;
	}

	/**
	 * Recursively list a directory on a remote server via the system API.
	 *
	 * @param int           $rServerID Target server id.
	 * @param string        $rDirectory Directory to scan.
	 * @param string[]|null $rAllowed   Allowed file extensions filter.
	 * @param bool          $rStat      Files only, as path => modification time, read
	 *                                  page by page (a node from before this option
	 *                                  answers its plain list, returned as it is).
	 * @return array|null Decoded directory listing, or null on failure; with $rStat
	 *                    never part of a listing as the whole of it.
	 */
	public static function scanRecursive(int $rServerID, string $rDirectory, ?array $rAllowed = null, bool $rStat = false) {
		$rRequest = ['action' => 'scandir_recursive', 'dir' => $rDirectory, 'allowed' => implode('|', $rAllowed ?? [])];
		if (!$rStat) {
			return json_decode((string) NodeRpc::request($rServerID, $rRequest), true);
		}
		$rFiles = [];
		$rAfter = null;
		for ($rPage = 0; $rPage < self::MAX_LISTING_PAGES; $rPage++) {
			$rAnswer = json_decode((string) NodeRpc::request($rServerID, $rRequest + ['stat' => 1] + ($rAfter !== null ? ['after' => $rAfter] : [])), true);
			if (!is_array($rAnswer) || !array_key_exists('files', $rAnswer)) {
				// No answer, a refusal ({"result":false}), or a node from before `stat` (its plain list).
				return $rAnswer;
			}
			$rFiles += (array) $rAnswer['files'];
			if (($rAnswer['next'] ?? null) === null) {
				return $rFiles;
			}
			$rAfter = (string) $rAnswer['next'];
		}
		return null;
	}

	/**
	 * List a directory on a remote server via the system API.
	 *
	 * @param int           $rServerID  Target server id.
	 * @param string        $rDirectory Directory to scan.
	 * @param string[]|null $rAllowed   Allowed file extensions filter.
	 * @return array|null Decoded directory listing, or null on failure.
	 */
	public static function listDir(int $rServerID, string $rDirectory, ?array $rAllowed = null) {
		return json_decode(NodeRpc::request($rServerID, ['action' => 'scandir', 'dir' => $rDirectory, 'allowed' => implode('|', $rAllowed)]), true);
	}
}
