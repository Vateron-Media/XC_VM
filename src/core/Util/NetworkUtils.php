<?php

/**
 * Network Utilities
 *
 * IP address operations, CIDR matching, subnet checks,
 * and user IP detection.
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
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

    /**
     * Возвращает IP-адрес текущего клиента (REMOTE_ADDR).
     *
     * @return string
     */
    public static function getUserIP() {
        return $_SERVER['REMOTE_ADDR'];
    }

    public static function startDownload($rType, $rUser, $rDownloadPID, $rFloodLimit) {
        if ($rFloodLimit != 0) {
            if (!$rUser['is_restreamer']) {
                $rFile = FLOOD_TMP_PATH . $rUser['id'] . '_downloads';
                $rFloodRow = array('epg' => array(), 'playlist' => array());
                if (file_exists($rFile) && time() - filemtime($rFile) < 10) {
                    $rExisting = json_decode(file_get_contents($rFile), true);
                    if (is_array($rExisting)) {
                        $rFloodRow = array_merge($rFloodRow, $rExisting);
                    }
                    $rActive = array();
                    foreach (($rFloodRow[$rType] ?? []) as $rPID) {
                        if (ProcessManager::isRunning($rPID, 'php-fpm') && $rPID != $rDownloadPID) {
                            $rActive[] = $rPID;
                        }
                    }
                    $rFloodRow[$rType] = $rActive;
                }
                $rAllow = false;
                if (count($rFloodRow[$rType]) >= $rFloodLimit) {
                } else {
                    $rFloodRow[$rType][] = $rDownloadPID;
                    $rAllow = true;
                }
                file_put_contents($rFile, json_encode($rFloodRow), LOCK_EX);
                return $rAllow;
            } else {
                return true;
            }
        } else {
            return true;
        }
    }

    public static function stopDownload($rType, $rUser, $rDownloadPID, $rFloodLimit) {
        if ($rFloodLimit != 0) {
            if (!$rUser['is_restreamer']) {
                $rFile = FLOOD_TMP_PATH . $rUser['id'] . '_downloads';
                if (file_exists($rFile)) {
                    $rFloodRow[$rType] = array();
                    foreach (json_decode(file_get_contents($rFile), true)[$rType] as $rPID) {
                        if (!(ProcessManager::isRunning($rPID, 'php-fpm') && $rPID != $rDownloadPID)) {
                        } else {
                            $rFloodRow[$rType][] = $rPID;
                        }
                    }
                } else {
                    $rFloodRow = array('epg' => array(), 'playlist' => array());
                }
                file_put_contents($rFile, json_encode($rFloodRow), LOCK_EX);
            } else {
                return null;
            }
        } else {
            return null;
        }
    }
}
