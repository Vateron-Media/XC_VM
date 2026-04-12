<?php

/**
 * DomainResolver — domain resolver
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class DomainResolver {
	public static function resolve($rServerID, $rForceSSL = false) {
		global $rServers, $rSettings;
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
			$rProxyIDs = array_keys(ConnectionTracker::getProxies($rServerID, true));
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

		$rHost = ($_SERVER['HTTP_HOST'] ?? '');
		if (strpos($rHost, ':') !== false) {
			list($rDomain, $rAccessPort) = explode(':', $rHost, 2);
		} else {
			$rDomain = $rHost;
		}

		if ($rProxied || $rSettings['use_mdomain_in_lists'] == 1) {
			$rResellerDomains = CacheReader::get('reseller_domains') ?: array();
			if (!(strlen($rDomain) > 0 && in_array(strtolower($rDomain), $rResellerDomains))) {
				if (empty($rServers[$rServerID]['domain_name'])) {
					$rDomain = escapeshellcmd($rServers[$rServerID]['server_ip']);
				} else if (filter_var($rDomain, FILTER_VALIDATE_IP)) {
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
