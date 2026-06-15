<?php

declare(strict_types=1);

/**
 * Bruteforce / Flood Guard
 *
 * Centralized rate-limiting and brute-force protection.
 * (identical logic, unified here).
 *
 * @package XC_VM_Core_Auth
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BruteforceGuard {
    private static function getSettings(): array {
        if (!empty(SettingsManager::getAll())) {
            return SettingsManager::getAll();
        }
        if (!empty($GLOBALS['rSettings'])) {
            return $GLOBALS['rSettings'];
        }
        return array();
    }

    /**
     * Resolve the user's IP address.
     *
     * @return string
     */
    private static function getUserIP(): string {
        if (class_exists('NetworkUtils', false)) {
            return NetworkUtils::getUserIP();
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Get the allowed IPs list.
     *
     * @return array
     */
    private static function getAllowedIPs(): array {
        if (class_exists('ServerRepository', false)) {
            return ServerRepository::getAllowedIPs();
        }
        if (isset($GLOBALS['rAllowedIPs'])) {
            return $GLOBALS['rAllowedIPs'];
        }
        return array();
    }

    /**
     * Get the blocked IPs list.
     *
     * @return array
     */
    private static function getBlockedIPs(): array {
        if (class_exists('BlocklistService', false)) {
            return BlocklistService::getBlockedIPs();
        }
        if (isset($GLOBALS['rBlockedIPs'])) {
            return $GLOBALS['rBlockedIPs'];
        }
        return array();
    }

    /**
     * Get database instance.
     *
     * @return object|null
     */
    private static function getDB(): ?object {
        if (class_exists('DatabaseFactory', false) && DatabaseFactory::get() !== null) {
            return DatabaseFactory::get();
        }
        global $db;
        if (is_object($db)) {
            return $db;
        }
        return null;
    }

    /**
     * Block an IP: insert into DB (or signal if in cached/streaming mode).
     *
     * @param string $ip
     * @param string $reason
     * @param bool   $useCachedMode  Use signal-based blocking
     */
    private static function blockIP(string $ip, string $reason, bool $useCachedMode = false): void {
        if ($useCachedMode && !empty($GLOBALS['rCached'])) {
            $signalKey = (stripos($reason, 'BRUTEFORCE') !== false ? 'bruteforce_attack' : 'flood_attack');
            RedisManager::setSignal($signalKey . '/' . $ip, 1);
        } else {
            $db = self::getDB();
            if ($db) {
                $db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $ip, $reason, time());
            }
            // Force-refresh blocked IPs cache
            if (class_exists('BlocklistService', false) && method_exists('BlocklistService', 'getBlockedIPs')) {
                BlocklistService::getBlockedIPs(true);
            }
        }
        touch(FLOOD_TMP_PATH . 'block_' . $ip);
    }

    /**
     * Check for flood attacks (too many requests per time window).
     *
     * @param string|null $ip            IP address (auto-detected if null)
     * @param bool        $useCachedMode Use signal-based blocking for streaming context
     */
    public static function checkFlood(?string $ip = null, bool $useCachedMode = false): void {
        $settings = self::getSettings();
        if (empty($settings['flood_limit']) || $settings['flood_limit'] == 0) {
            return;
        }

        if (!$ip) {
            $ip = self::getUserIP();
        }

        $allowedIPs = self::getAllowedIPs();
        if (empty($ip) || in_array($ip, $allowedIPs)) {
            return;
        }

        $floodExclude = array_filter(array_unique(explode(',', $settings['flood_ips_exclude'])));
        if (in_array($ip, $floodExclude)) {
            return;
        }

        $ipFile = FLOOD_TMP_PATH . $ip;
        if (file_exists($ipFile)) {
            $floodRow = json_decode(file_get_contents($ipFile), true);
            $floodSeconds = $settings['flood_seconds'];
            $floodLimit = $settings['flood_limit'];

            if (time() - $floodRow['last_request'] <= $floodSeconds) {
                $floodRow['requests']++;
                if ($floodLimit > $floodRow['requests']) {
                    $floodRow['last_request'] = time();
                    file_put_contents($ipFile, json_encode($floodRow), LOCK_EX);
                } else {
                    $blockedIPs = self::getBlockedIPs();
                    if (!in_array($ip, $blockedIPs)) {
                        self::blockIP($ip, 'FLOOD ATTACK', $useCachedMode);
                    } else {
                        touch(FLOOD_TMP_PATH . 'block_' . $ip);
                    }
                    unlink($ipFile);
                    return;
                }
            } else {
                $floodRow['requests'] = 0;
                $floodRow['last_request'] = time();
                file_put_contents($ipFile, json_encode($floodRow), LOCK_EX);
            }
        } else {
            file_put_contents($ipFile, json_encode(array('requests' => 0, 'last_request' => time())), LOCK_EX);
        }
    }

    /**
     * Check for brute-force attacks (too many unique MACs/usernames).
     *
     * @param string|null $ip            IP address (auto-detected if null)
     * @param string|null $mac           MAC address
     * @param string|null $username      Username
     * @param bool        $useCachedMode Use signal-based blocking for streaming context
     */
    public static function checkBruteforce(?string $ip = null, ?string $mac = null, ?string $username = null, bool $useCachedMode = false): void {
        if (!$mac && !$username) {
            return;
        }

        $settings = self::getSettings();

        if ($mac && $settings['bruteforce_mac_attempts'] == 0) {
            return;
        }
        if ($username && $settings['bruteforce_username_attempts'] == 0) {
            return;
        }

        if (!$ip) {
            $ip = self::getUserIP();
        }

        $allowedIPs = self::getAllowedIPs();
        if (empty($ip) || in_array($ip, $allowedIPs)) {
            return;
        }

        $floodExclude = array_filter(array_unique(explode(',', $settings['flood_ips_exclude'])));
        if (in_array($ip, $floodExclude)) {
            return;
        }

        $floodType = (!is_null($mac) ? 'mac' : 'user');
        $term = (!is_null($mac) ? $mac : $username);
        $ipFile = FLOOD_TMP_PATH . $ip . '_' . $floodType;

        if (file_exists($ipFile)) {
            $floodRow = json_decode(file_get_contents($ipFile), true);
            $floodSeconds = intval($settings['bruteforce_frequency']);
            $floodLimit = intval($settings[array('mac' => 'bruteforce_mac_attempts', 'user' => 'bruteforce_username_attempts')[$floodType]]);
            $floodRow['attempts'] = self::truncateAttempts($floodRow['attempts'], $floodSeconds);

            if (!in_array($term, array_keys($floodRow['attempts']))) {
                $floodRow['attempts'][$term] = time();
                if ($floodLimit > count($floodRow['attempts'])) {
                    file_put_contents($ipFile, json_encode($floodRow), LOCK_EX);
                } else {
                    $blockedIPs = self::getBlockedIPs();
                    if (!in_array($ip, $blockedIPs)) {
                        self::blockIP($ip, 'BRUTEFORCE ' . strtoupper($floodType) . ' ATTACK', $useCachedMode);
                    } else {
                        touch(FLOOD_TMP_PATH . 'block_' . $ip);
                    }
                    unlink($ipFile);
                    return;
                }
            }
        } else {
            $floodRow = array('attempts' => array($term => time()));
            file_put_contents($ipFile, json_encode($floodRow), LOCK_EX);
        }
    }

    /**
     * Check for auth flood (too many auth requests from same user+IP).
     *
     * @param array       $user          User info array (must have 'id' and 'is_restreamer')
     * @param string|null $ip            IP address (auto-detected if null)
     */
    public static function checkAuthFlood(array $user, ?string $ip = null): void {
        $settings = self::getSettings();
        if (empty($settings['auth_flood_limit']) || $settings['auth_flood_limit'] == 0) {
            return;
        }

        if (!empty($user['is_restreamer'])) {
            return;
        }

        if (!$ip) {
            $ip = self::getUserIP();
        }

        $allowedIPs = self::getAllowedIPs();
        if (empty($ip) || in_array($ip, $allowedIPs)) {
            return;
        }

        $floodExclude = array_filter(array_unique(explode(',', $settings['flood_ips_exclude'])));
        if (in_array($ip, $floodExclude)) {
            return;
        }

        $userFile = FLOOD_TMP_PATH . intval($user['id']) . '_' . $ip;
        if (file_exists($userFile)) {
            $floodRow = json_decode(file_get_contents($userFile), true);

            if (isset($floodRow['block_until']) && time() < $floodRow['block_until']) {
                sleep(intval($settings['auth_flood_sleep']));
            }

            $floodSeconds = $settings['auth_flood_seconds'];
            $floodLimit = $settings['auth_flood_limit'];
            $floodRow['attempts'] = self::truncateAttempts($floodRow['attempts'], $floodSeconds, true);

            if (!($floodLimit > count($floodRow['attempts']))) {
                $floodRow['block_until'] = time() + intval($settings['auth_flood_seconds']);
            }

            $floodRow['attempts'][] = time();
            file_put_contents($userFile, json_encode($floodRow), LOCK_EX);
        } else {
            file_put_contents($userFile, json_encode(array('attempts' => array(time()))), LOCK_EX);
        }
    }

    /**
     * Filter out expired attempts from the list.
     *
     * @param array $attempts   Array of attempts (keyed or indexed by time)
     * @param int   $frequency  Time window in seconds
     * @param bool  $list       If true, treat as indexed array; otherwise as associative
     * @return array Filtered attempts
     */
    public static function truncateAttempts(array $attempts, int $frequency, bool $list = false): array {
        $allowed = array();
        $now = time();

        if ($list) {
            foreach ($attempts as $attemptTime) {
                if ($now - $attemptTime <= $frequency) {
                    $allowed[] = $attemptTime;
                }
            }
        } else {
            foreach ($attempts as $attempt => $attemptTime) {
                if ($now - $attemptTime <= $frequency) {
                    $allowed[$attempt] = $attemptTime;
                }
            }
        }

        return $allowed;
    }
}
