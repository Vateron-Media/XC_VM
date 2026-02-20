<?php

class DomainResolver {
	public static function resolve($rServers, $rSettings, $rServerID, $rForceSSL = false, $rGetProxies = null, $rGetCache = null) {
		$rOriginatorID = null;
		if ($rForceSSL) {
			$rProtocol = 'https';
		} else {
			if (isset($_SERVER['SERVER_PORT']) && $rSettings['keep_protocol']) {
				$rProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http');
			} else {
				$rProtocol = $rServers[$rServerID]['server_protocol'];
			}
		}

		$rProxied = $rServers[$rServerID]['enable_proxy'];
		if ($rProxied) {
			$rProxyIDs = is_callable($rGetProxies) ? array_keys(call_user_func($rGetProxies, $rServerID, true)) : array();
			if (count($rProxyIDs) == 0) {
				$rProxyIDs = is_callable($rGetProxies) ? array_keys(call_user_func($rGetProxies, $rServerID, false)) : array();
			}
			if (count($rProxyIDs) != 0) {
				$rOriginatorID = $rServerID;
				$rServerID = $rProxyIDs[array_rand($rProxyIDs)];
			} else {
				return '';
			}
		}

		$rHost = ($_SERVER['HTTP_HOST'] ?? '');
		if (strpos($rHost, ':') !== false) {
			list($rDomain, $rAccessPort) = explode(':', $rHost, 2);
		} else {
			$rDomain = $rHost;
		}

		if ($rProxied || $rSettings['use_mdomain_in_lists'] == 1) {
			$rResellerDomains = is_callable($rGetCache) ? (call_user_func($rGetCache, 'reseller_domains') ?: array()) : array();
			if (!(strlen($rDomain) > 0 && in_array(strtolower($rDomain), $rResellerDomains))) {
				if (empty($rServers[$rServerID]['domain_name'])) {
					$rDomain = escapeshellcmd($rServers[$rServerID]['server_ip']);
				} else {
					$rDomain = str_replace(array('http://', '/', 'https://'), '', escapeshellcmd(explode(',', $rServers[$rServerID]['domain_name'])[0]));
				}
			}
		} else {
			if (strlen($rDomain) == 0) {
				if (empty($rServers[$rServerID]['domain_name'])) {
					$rDomain = escapeshellcmd($rServers[$rServerID]['server_ip']);
				} else {
					$rDomain = str_replace(array('http://', '/', 'https://'), '', escapeshellcmd(explode(',', $rServers[$rServerID]['domain_name'])[0]));
				}
			}
		}

		$rServerURL = $rProtocol . '://' . $rDomain . ':' . $rServers[$rServerID][$rProtocol . '_broadcast_port'] . '/';
		if ($rServers[$rServerID]['server_type'] == 1 && $rOriginatorID && $rServers[$rOriginatorID]['is_main'] == 0) {
			$rServerURL .= md5($rServerID . '_' . $rOriginatorID . '_' . OPENSSL_EXTRA) . '/';
		}

		return $rServerURL;
	}
}
