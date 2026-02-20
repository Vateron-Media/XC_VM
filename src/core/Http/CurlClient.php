<?php

class CurlClient {
	public static function getMultiCURL($rServers, $rURLs, $callback = null, $rTimeout = 5) {
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

	public static function serverRequest($rServers, $rServerID, $rURL, $rPostData = array()) {
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
