<?php

class BlocklistRepository {
	public static function getProxyIPs($rServers, $rGetCacheCallback, $rSetCacheCallback, $rForce = false) {
		if (!$rForce && is_callable($rGetCacheCallback)) {
			$rCache = call_user_func($rGetCacheCallback, 'proxy_servers', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}

		$rOutput = array();
		foreach ($rServers as $rServer) {
			if ($rServer['server_type'] == 1) {
				$rOutput[$rServer['server_ip']] = $rServer;
				if ($rServer['private_ip']) {
					$rOutput[$rServer['private_ip']] = $rServer;
				}
			}
		}

		if (is_callable($rSetCacheCallback)) {
			call_user_func($rSetCacheCallback, 'proxy_servers', $rOutput);
		}

		return $rOutput;
	}

	public static function getBlockedUA($db, $rGetCacheCallback, $rSetCacheCallback, $rForce = false) {
		if (!$rForce && is_callable($rGetCacheCallback)) {
			$rCache = call_user_func($rGetCacheCallback, 'blocked_ua', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}

		$db->query('SELECT id,exact_match,LOWER(user_agent) as blocked_ua FROM `blocked_uas`');
		$rOutput = $db->get_rows(true, 'id');

		if (is_callable($rSetCacheCallback)) {
			call_user_func($rSetCacheCallback, 'blocked_ua', $rOutput);
		}

		return $rOutput;
	}

	public static function getBlockedIPs($db, $rGetCacheCallback, $rSetCacheCallback, $rForce = false) {
		if (!$rForce && is_callable($rGetCacheCallback)) {
			$rCache = call_user_func($rGetCacheCallback, 'blocked_ips', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}

		$rOutput = array();
		$db->query('SELECT `ip` FROM `blocked_ips`');
		foreach ($db->get_rows() as $rRow) {
			$rOutput[] = $rRow['ip'];
		}

		if (is_callable($rSetCacheCallback)) {
			call_user_func($rSetCacheCallback, 'blocked_ips', $rOutput);
		}

		return $rOutput;
	}

	public static function getBlockedISP($db, $rGetCacheCallback, $rSetCacheCallback, $rForce = false) {
		if (!$rForce && is_callable($rGetCacheCallback)) {
			$rCache = call_user_func($rGetCacheCallback, 'blocked_isp', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}

		$db->query('SELECT id,isp,blocked FROM `blocked_isps`');
		$rOutput = $db->get_rows();

		if (is_callable($rSetCacheCallback)) {
			call_user_func($rSetCacheCallback, 'blocked_isp', $rOutput);
		}

		return $rOutput;
	}

	public static function getBlockedServers($db, $rGetCacheCallback, $rSetCacheCallback, $rForce = false) {
		if (!$rForce && is_callable($rGetCacheCallback)) {
			$rCache = call_user_func($rGetCacheCallback, 'blocked_servers', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}

		$rOutput = array();
		$db->query('SELECT `asn` FROM `blocked_asns` WHERE `blocked` = 1;');
		foreach ($db->get_rows() as $rRow) {
			$rOutput[] = $rRow['asn'];
		}

		if (is_callable($rSetCacheCallback)) {
			call_user_func($rSetCacheCallback, 'blocked_servers', $rOutput);
		}

		return $rOutput;
	}

	public static function getBlockedIPsSimple($db) {
		$rReturn = array();
		$db->query('SELECT * FROM `blocked_ips` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getRTMPIPsSimple($db) {
		$rReturn = array();
		$db->query('SELECT * FROM `rtmp_ips` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}
}
