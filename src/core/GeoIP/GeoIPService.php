<?php

/**
 * GeoIPService — GeoIP/ISP lookup и CIDR matching.
 *
 * Извлечено из GeoIPService::getIPInfo(), getISP(), matchCIDR().
 * Использует MaxMind GeoLite2 и GeoISP базы с файловым кэшированием.
 */
class GeoIPService {

	/**
	 * Получить GeoIP-информацию по IP-адресу (GeoLite2).
	 *
	 * Результат кэшируется в файл CONS_TMP_PATH/md5(ip)_geo2.
	 *
	 * @param string $rIP
	 * @return array|false
	 */
	public static function getIPInfo($rIP) {
		if (!empty($rIP)) {
			if (!file_exists(CONS_TMP_PATH . md5($rIP) . '_geo2')) {
				$rGeoIP = new MaxMind\Db\Reader(GEOLITE2_BIN);
				$rResponse = $rGeoIP->get($rIP);
				$rGeoIP->close();
				if ($rResponse) {
					file_put_contents(CONS_TMP_PATH . md5($rIP) . '_geo2', json_encode($rResponse));
				}
				return $rResponse;
			}
			return json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_geo2'), true);
		}
		return false;
	}

	/**
	 * Получить ISP-информацию по IP-адресу (GeoISP).
	 *
	 * Результат кэшируется в файл CONS_TMP_PATH/md5(ip)_isp.
	 *
	 * @param string $rIP
	 * @return array|false
	 */
	public static function getISP($rIP) {
		if (!empty($rIP)) {
			$rResponse = (file_exists(CONS_TMP_PATH . md5($rIP) . '_isp') ? json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_isp'), true) : null);
			if (!is_array($rResponse)) {
				$rGeoIP = new MaxMind\Db\Reader(GEOISP_BIN);
				$rResponse = $rGeoIP->get($rIP);
				$rGeoIP->close();
				if (is_array($rResponse)) {
					file_put_contents(CONS_TMP_PATH . md5($rIP) . '_isp', json_encode($rResponse));
				}
			}
			return $rResponse;
		}
		return false;
	}

	/**
	 * Проверить IP на соответствие CIDR-блокам для ASN.
	 *
	 * @param string $rASN ASN identifier
	 * @param string $rIP IP address
	 * @return array|null Matching CIDR data or null
	 */
	public static function matchCIDR($rASN, $rIP) {
		if (file_exists(CIDR_TMP_PATH . $rASN)) {
			$rCIDRs = json_decode(file_get_contents(CIDR_TMP_PATH . $rASN), true);
			foreach ($rCIDRs as $rCIDR => $rData) {
				if (ip2long($rData[1]) <= ip2long($rIP) && ip2long($rIP) <= ip2long($rData[2])) {
					return $rData;
				}
			}
		}
		return null;
	}
}
