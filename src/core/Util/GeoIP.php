<?php

/**
 * XC_VM — GeoIP Utilities
 *
 * MaxMind GeoIP2/GeoLite2 database lookups for IP geolocation
 * and ISP detection with file-based caching.
 *
 * ---------------------------------------------------------------
 * What it replaces:
 * ---------------------------------------------------------------
 *
 *   CoreUtilities::getISP()     → GeoIP::getISP()
 *   CoreUtilities::getIPInfo()  → GeoIP::getCountry()
 *   CoreUtilities::checkISP()   → GeoIP::isISPBlocked()
 *   CoreUtilities::checkServer() → GeoIP::isASNBlocked()
 *
 * ---------------------------------------------------------------
 * Dependencies:
 * ---------------------------------------------------------------
 *
 *   - GEOISP_BIN constant    — path to GeoIP2-ISP.mmdb
 *   - GEOLITE2_BIN constant  — path to GeoLite2-City.mmdb
 *   - CONS_TMP_PATH constant — path for file cache
 *   - MaxMind\Db\Reader class (included in bin/maxmind/)
 *
 * @see CoreUtilities::getISP()
 * @see CoreUtilities::getIPInfo()
 */

class GeoIP {

    /** @var string Cache directory for GeoIP lookups */
    protected static $cachePath = null;

    /**
     * Get ISP information for an IP address
     *
     * Results are cached in CONS_TMP_PATH/{md5(ip)}_isp
     *
     * @param string $ip IP address
     * @return array|false ISP data array or false
     */
    public static function getISP($ip) {
        if (empty($ip)) {
            return false;
        }

        $cachePath = self::getCachePath();
        $cacheFile = $cachePath . md5($ip) . '_isp';

        if (file_exists($cacheFile)) {
            return json_decode(file_get_contents($cacheFile), true);
        }

        if (!defined('GEOISP_BIN') || !file_exists(GEOISP_BIN)) {
            return false;
        }

        try {
            $reader = new \MaxMind\Db\Reader(GEOISP_BIN);
            $response = $reader->get($ip);
            $reader->close();

            if ($response) {
                file_put_contents($cacheFile, json_encode($response));
            }

            return $response;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get country/city information for an IP address
     *
     * Results are cached in CONS_TMP_PATH/{md5(ip)}_geo2
     *
     * @param string $ip IP address
     * @return array|false GeoIP data array or false
     */
    public static function getCountry($ip) {
        if (empty($ip)) {
            return false;
        }

        $cachePath = self::getCachePath();
        $cacheFile = $cachePath . md5($ip) . '_geo2';

        if (file_exists($cacheFile)) {
            return json_decode(file_get_contents($cacheFile), true);
        }

        if (!defined('GEOLITE2_BIN') || !file_exists(GEOLITE2_BIN)) {
            return false;
        }

        try {
            $reader = new \MaxMind\Db\Reader(GEOLITE2_BIN);
            $response = $reader->get($ip);
            $reader->close();

            if ($response) {
                file_put_contents($cacheFile, json_encode($response));
            }

            return $response;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if an ISP name is in the blocked list
     *
     * @param string $ispName ISP name to check
     * @param array $blockedISPs Array of blocked ISP entries (each with 'isp' and 'blocked' keys)
     * @return int 0 = not blocked, 1+ = blocked type
     */
    public static function isISPBlocked($ispName, array $blockedISPs) {
        foreach ($blockedISPs as $entry) {
            if (strtolower($ispName) === strtolower($entry['isp'])) {
                return (int)$entry['blocked'];
            }
        }
        return 0;
    }

    /**
     * Check if an ASN is in the blocked servers list
     *
     * @param string|int $asn ASN to check
     * @param array $blockedServers Array of blocked ASNs
     * @return bool
     */
    public static function isASNBlocked($asn, array $blockedServers) {
        return in_array($asn, $blockedServers);
    }

    /**
     * Get the cache directory path
     *
     * @return string
     */
    protected static function getCachePath() {
        if (self::$cachePath !== null) {
            return self::$cachePath;
        }

        if (defined('CONS_TMP_PATH')) {
            self::$cachePath = CONS_TMP_PATH;
        } else {
            self::$cachePath = (defined('TMP_PATH') ? TMP_PATH : '/tmp/') . 'connections/';
        }

        return self::$cachePath;
    }
}
