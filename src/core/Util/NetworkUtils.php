<?php

/**
 * XC_VM — Network Utilities
 *
 * IP address operations, CIDR matching, subnet checks,
 * and user IP detection.
 *
 * ---------------------------------------------------------------
 * What it replaces:
 * ---------------------------------------------------------------
 *
 *   CoreUtilities::getUserIP()  → NetworkUtils::getClientIP()
 *   Inline CIDR checks         → NetworkUtils::ipInCIDR()
 *   Inline IP validation       → NetworkUtils::isValidIP()
 *
 * @see CoreUtilities::getUserIP()
 */

class NetworkUtils {

    /**
     * Get the client's IP address
     *
     * @return string IP address
     */
    public static function getClientIP() {
        // Check proxy headers first
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    /**
     * Check if an IP address is in a CIDR range
     *
     * @param string $ip IP address to check
     * @param string $cidr CIDR notation (e.g., "192.168.1.0/24")
     * @return bool
     */
    public static function ipInCIDR($ip, $cidr) {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        list($subnet, $bits) = explode('/', $cidr);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - (int)$bits);

        return ($ip & $mask) === ($subnet & $mask);
    }

    /**
     * Check if an IP is in any of the given CIDR ranges
     *
     * @param string $ip IP address
     * @param array $cidrs Array of CIDR strings
     * @return bool
     */
    public static function ipInAnyCIDR($ip, array $cidrs) {
        foreach ($cidrs as $cidr) {
            if (self::ipInCIDR($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate an IP address
     *
     * @param string $ip IP address
     * @param bool $allowPrivate Allow private/reserved ranges
     * @return bool
     */
    public static function isValidIP($ip, $allowPrivate = true) {
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;

        if (!$allowPrivate) {
            $flags |= FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
    }

    /**
     * Check if an IP is a private/reserved address
     *
     * @param string $ip IP address
     * @return bool
     */
    public static function isPrivateIP($ip) {
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Convert a long integer to IP address
     *
     * @param int $long
     * @return string
     */
    public static function longToIP($long) {
        return long2ip($long);
    }

    /**
     * Convert an IP address to long integer
     *
     * @param string $ip
     * @return int
     */
    public static function ipToLong($ip) {
        return ip2long($ip);
    }
}
