<?php

/**
 * CurlClient — curl client
 *
 * @package XC_VM_Core_Http
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CurlClient {
	/**
	 * Execute multiple HTTP requests concurrently (skipping offline servers).
	 *
	 * @param array         $rURLs    Map of serverId => ['url' => string, 'postdata' => array|null].
	 * @param callable|null $callback Optional callback applied to each response body.
	 * @param int           $rTimeout Per-request timeout in seconds.
	 * @return array Map of serverId => response (false for offline servers).
	 */
	public static function getMultiCURL($rURLs, $callback = null, $rTimeout = 5) {
		global $rServers;
		if (empty($rURLs)) {
			return array();
		}

		$rOffline = array();
		$rCurl = array();
		$rResults = array();
		$rMulti = curl_multi_init();

		foreach ($rURLs as $rKey => $rValue) {
			if (!isset($rServers[$rKey]) || !$rServers[$rKey]['server_online']) {
				$rOffline[] = $rKey;
				continue;
			}

			$rCurl[$rKey] = curl_init();
			curl_setopt($rCurl[$rKey], CURLOPT_URL, $rValue['url']);
			curl_setopt($rCurl[$rKey], CURLOPT_RETURNTRANSFER, true);
			curl_setopt($rCurl[$rKey], CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($rCurl[$rKey], CURLOPT_CONNECTTIMEOUT, 5);
			curl_setopt($rCurl[$rKey], CURLOPT_TIMEOUT, $rTimeout);
			curl_setopt($rCurl[$rKey], CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($rCurl[$rKey], CURLOPT_SSL_VERIFYPEER, 0);

			if ($rValue['postdata'] == null) {
			} else {
				curl_setopt($rCurl[$rKey], CURLOPT_POST, true);
				curl_setopt($rCurl[$rKey], CURLOPT_POSTFIELDS, http_build_query($rValue['postdata']));
			}

			curl_multi_add_handle($rMulti, $rCurl[$rKey]);
		}

		$rActive = null;
		do {
			$rMultiExec = curl_multi_exec($rMulti, $rActive);
		} while ($rMultiExec == CURLM_CALL_MULTI_PERFORM);

		while ($rActive && $rMultiExec == CURLM_OK) {
			if (curl_multi_select($rMulti) != -1) {
			} else {
				usleep(50000);
			}
			do {
				$rMultiExec = curl_multi_exec($rMulti, $rActive);
			} while ($rMultiExec == CURLM_CALL_MULTI_PERFORM);
		}

		foreach ($rCurl as $rKey => $rValue) {
			$rResults[$rKey] = curl_multi_getcontent($rValue);
			if ($callback == null) {
			} else {
				$rResults[$rKey] = call_user_func($callback, $rResults[$rKey], true);
			}
			curl_multi_remove_handle($rMulti, $rValue);
		}

		foreach ($rOffline as $rKey) {
			$rResults[$rKey] = false;
		}

		curl_multi_close($rMulti);
		return $rResults;
	}

	/**
	 * Perform a simple GET request.
	 *
	 * @param string $rURL  URL to fetch.
	 * @param bool   $rWait Return the response body (true) or fire-and-forget (false).
	 * @return string|bool Response body, or the curl result.
	 */
	public static function getURL($rURL, $rWait = true) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3);
		curl_setopt($ch, CURLOPT_URL, $rURL);
		curl_setopt($ch, CURLOPT_USERAGENT, 'XC_VM/' . XC_VM_VERSION);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, $rWait);
		$rReturn = curl_exec($ch);
		curl_close($ch);
		return $rReturn;
	}

	/**
	 * POST to a configured server's URL with a retry (online servers only).
	 *
	 * @param int    $rServerID Target server id (must be online).
	 * @param string $rURL      Request URL.
	 * @param array  $rPostData Optional POST fields.
	 * @return string|bool Response body, or false if the server is offline/unreachable.
	 */
	public static function serverRequest($rServerID, $rURL, $rPostData = array()) {
		global $rServers;
		if (!(is_array($rServers) && isset($rServers[$rServerID]) && $rServers[$rServerID]['server_online'])) {
			return false;
		}

		$rOutput = false;
		$i = 1;
		while ($i <= 2) {
			$rCurl = curl_init();
			curl_setopt($rCurl, CURLOPT_URL, $rURL);
			curl_setopt($rCurl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:9.0) Gecko/20100101 Firefox/9.0');
			curl_setopt($rCurl, CURLOPT_HEADER, 0);
			curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($rCurl, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($rCurl, CURLOPT_TIMEOUT, 10);
			curl_setopt($rCurl, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($rCurl, CURLOPT_FRESH_CONNECT, true);
			curl_setopt($rCurl, CURLOPT_FORBID_REUSE, true);
			curl_setopt($rCurl, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($rCurl, CURLOPT_SSL_VERIFYPEER, 0);
			if (empty($rPostData)) {
			} else {
				curl_setopt($rCurl, CURLOPT_POST, true);
				curl_setopt($rCurl, CURLOPT_POSTFIELDS, http_build_query($rPostData));
			}
			$rOutput = curl_exec($rCurl);
			$rResponseCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
			$rError = curl_errno($rCurl);
			@curl_close($rCurl);
			if ($rError != 0 || $rResponseCode != 200) {
				$i++;
				break;
			}
		}

		return $rOutput;
	}
}
