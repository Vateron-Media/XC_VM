<?php

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
	public static function request($rData, $rTimeout = 5) {
		ini_set('default_socket_timeout', $rTimeout);
		$rAPI = 'http://127.0.0.1:' . intval(ServerRepository::getAll()[SERVER_ID]['http_broadcast_port']) . '/admin/api';

		if (!empty(SettingsManager::getAll()['api_pass'])) {
			$rData['api_pass'] = SettingsManager::getAll()['api_pass'];
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

	public static function systemRequest($rServerID, $rData, $rTimeout = 5) {
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

			$rResult = curl_exec($ch);

			return $rResult;
		}
		return null;
	}

	public static function asyncRequest($rServerIDs, $rData) {
		$rURLs = array();
		global $rServers;

		foreach ($rServerIDs as $rServerID) {
			if (!$rServers[$rServerID]['server_online']) {
			} else {
				$rURLs[$rServerID] = array('url' => $rServers[$rServerID]['api_url'], 'postdata' => $rData);
			}
		}
		CurlClient::getMultiCURL($rURLs);

		return array('result' => true);
	}

	public static function scanRecursive($rServerID, $rDirectory, $rAllowed = null) {
		return json_decode(self::systemRequest($rServerID, array('action' => 'scandir_recursive', 'dir' => $rDirectory, 'allowed' => implode('|', $rAllowed))), true);
	}

	public static function listDir($rServerID, $rDirectory, $rAllowed = null) {
		return json_decode(self::systemRequest($rServerID, array('action' => 'scandir', 'dir' => $rDirectory, 'allowed' => implode('|', $rAllowed))), true);
	}
}
