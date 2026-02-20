<?php

/**
 * XC_VM — Bruteforce / Flood Guard
 *
 * Centralized rate-limiting and brute-force protection.
 * Extracted from both CoreUtilities and StreamingUtilities
 * (identical logic, unified here).
 *
 * ---------------------------------------------------------------
 * What it replaces:
 * ---------------------------------------------------------------
 *   CoreUtilities::checkFlood()        → BruteforceGuard::checkFlood()
 *   CoreUtilities::checkBruteforce()   → BruteforceGuard::checkBruteforce()
 *   CoreUtilities::checkAuthFlood()    → BruteforceGuard::checkAuthFlood()
 *   CoreUtilities::truncateAttempts()  → BruteforceGuard::truncateAttempts()
 *   StreamingUtilities::checkFlood()        → same
 *   StreamingUtilities::checkBruteforce()   → same
 *   StreamingUtilities::checkAuthFlood()    → same
 *   StreamingUtilities::truncateAttempts()  → same
 *
 * @see CoreUtilities::checkFlood()
 * @see StreamingUtilities::checkFlood()
 */

class BruteforceGuard {

    /**
     * Resolve settings array from either CoreUtilities or StreamingUtilities.
     *
     * @return array
     */
    private static function getSettings() {
        if (class_exists('CoreUtilities', false) && !empty(CoreUtilities::$rSettings)) {
            return CoreUtilities::$rSettings;
        }
        if (class_exists('StreamingUtilities', false) && !empty(StreamingUtilities::$rSettings)) {
            return StreamingUtilities::$rSettings;
        }
        return array();
    }

    /**
     * Resolve the user's IP address.
     *
     * @return string
     */
    private static function getUserIP() {
        if (class_exists('CoreUtilities', false) && method_exists('CoreUtilities', 'getUserIP')) {
            return CoreUtilities::getUserIP();
        }
        if (class_exists('StreamingUtilities', false) && method_exists('StreamingUtilities', 'getUserIP')) {
            return StreamingUtilities::getUserIP();
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Get the allowed IPs list.
     *
     * @return array
     */
    private static function getAllowedIPs() {
        if (class_exists('CoreUtilities', false) && isset(CoreUtilities::$rAllowedIPs)) {
            return CoreUtilities::$rAllowedIPs;
        }
        if (class_exists('StreamingUtilities', false) && isset(StreamingUtilities::$rAllowedIPs)) {
            return StreamingUtilities::$rAllowedIPs;
        }
        return array();
    }

    /**
     * Get the blocked IPs list.
     *
     * @return array
     */
    private static function getBlockedIPs() {
        if (class_exists('CoreUtilities', false) && isset(CoreUtilities::$rBlockedIPs)) {
            return CoreUtilities::$rBlockedIPs;
        }
        if (class_exists('StreamingUtilities', false) && isset(StreamingUtilities::$rBlockedIPs)) {
            return StreamingUtilities::$rBlockedIPs;
        }
        return array();
    }

    /**
     * Get database instance.
     *
     * @return Database|null
     */
    private static function getDB() {
        if (class_exists('CoreUtilities', false) && isset(CoreUtilities::$db)) {
            return CoreUtilities::$db;
        }
        if (class_exists('StreamingUtilities', false) && isset(StreamingUtilities::$db)) {
            return StreamingUtilities::$db;
        }
        return null;
    }

    /**
     * Block an IP: insert into DB (or signal if in cached/streaming mode).
     *
     * @param string $ip
     * @param string $reason
     * @param bool   $useCachedMode  Use signal-based blocking (StreamingUtilities context)
     */
    private static function blockIP($ip, $reason, $useCachedMode = false) {
        if ($useCachedMode && class_exists('StreamingUtilities', false) && !empty(StreamingUtilities::$rCached)) {
            $signalKey = (stripos($reason, 'BRUTEFORCE') !== false ? 'bruteforce_attack' : 'flood_attack');
            StreamingUtilities::setSignal($signalKey . '/' . $ip, 1);
        } else {
            $db = self::getDB();
            if ($db) {
                $db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $ip, $reason, time());
            }
            // Refresh blocked IPs list in CoreUtilities if available
            if (class_exists('CoreUtilities', false) && method_exists('CoreUtilities', 'getBlockedIPs')) {
                CoreUtilities::$rBlockedIPs = CoreUtilities::getBlockedIPs();
            }
        }
        touch(FLOOD_TMP_PATH . 'block_' . $ip);
    }

    /**
     * Check for flood attacks (too many requests per time window).
     *
     * @param string|null $ip            IP address (auto-detected if null)
     * @param bool        $useCachedMode Use signal-based blocking for streaming context
     * @return null
     */
    public static function checkFlood($ip = null, $useCachedMode = false) {
        $settings = self::getSettings();
        if (empty($settings['flood_limit']) || $settings['flood_limit'] == 0) {
            return null;
        }

        if (!$ip) {
            $ip = self::getUserIP();
        }

        $allowedIPs = self::getAllowedIPs();
        if (empty($ip) || in_array($ip, $allowedIPs)) {
            return null;
        }

        $floodExclude = array_filter(array_unique(explode(',', $settings['flood_ips_exclude'])));
        if (in_array($ip, $floodExclude)) {
            return null;
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
                    return null;
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
     * @return null
     */
    public static function checkBruteforce($ip = null, $mac = null, $username = null, $useCachedMode = false) {
        if (!$mac && !$username) {
            return null;
        }

        $settings = self::getSettings();

        if ($mac && $settings['bruteforce_mac_attempts'] == 0) {
            return null;
        }
        if ($username && $settings['bruteforce_username_attempts'] == 0) {
            return null;
        }

        if (!$ip) {
            $ip = self::getUserIP();
        }

        $allowedIPs = self::getAllowedIPs();
        if (empty($ip) || in_array($ip, $allowedIPs)) {
            return null;
        }

        $floodExclude = array_filter(array_unique(explode(',', $settings['flood_ips_exclude'])));
        if (in_array($ip, $floodExclude)) {
            return null;
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
                    return null;
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
     * @return null
     */
    public static function checkAuthFlood($user, $ip = null) {
        $settings = self::getSettings();
        if (empty($settings['auth_flood_limit']) || $settings['auth_flood_limit'] == 0) {
            return null;
        }

        if (!empty($user['is_restreamer'])) {
            return null;
        }

        if (!$ip) {
            $ip = self::getUserIP();
        }

        $allowedIPs = self::getAllowedIPs();
        if (empty($ip) || in_array($ip, $allowedIPs)) {
            return null;
        }

        $floodExclude = array_filter(array_unique(explode(',', $settings['flood_ips_exclude'])));
        if (in_array($ip, $floodExclude)) {
            return null;
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
    public static function truncateAttempts($attempts, $frequency, $list = false) {
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
