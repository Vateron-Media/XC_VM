<?php

/**
 * XC_VM — Unified Session Manager
 *
 * Consolidates duplicated session logic from admin/session.php
 * and reseller/session.php into a single class with role contexts.
 *
 * ---------------------------------------------------------------
 * What it replaces:
 * ---------------------------------------------------------------
 *
 *   BEFORE:
 *     admin/session.php    — $_SESSION['hash'], $_SESSION['last_activity']
 *     reseller/session.php — $_SESSION['reseller'], $_SESSION['rlast_activity']
 *     (identical logic, different session key names)
 *
 *   AFTER:
 *     SessionManager::start('admin');     // or 'reseller'
 *     SessionManager::requireAuth();      // redirect if not logged in
 *     SessionManager::getUser();          // get session hash/token
 *     SessionManager::destroy();          // logout
 *
 * ---------------------------------------------------------------
 * Session Keys by Context:
 * ---------------------------------------------------------------
 *
 *   Admin:
 *     hash          — session authentication hash
 *     last_activity — timestamp of last activity
 *     ip            — login IP
 *     code          — 2FA code
 *     verify        — 2FA verification flag
 *
 *   Reseller:
 *     reseller       — session authentication hash
 *     rlast_activity — timestamp of last activity
 *     rip            — login IP
 *     rcode          — 2FA code
 *     rverify        — 2FA verification flag
 *
 * ---------------------------------------------------------------
 * Backward Compatibility:
 * ---------------------------------------------------------------
 *
 *   admin/session.php will be reduced to:
 *     require_once MAIN_HOME . 'core/Auth/SessionManager.php';
 *     SessionManager::start('admin');
 *     SessionManager::requireAuth();
 *
 *   reseller/session.php will be reduced to:
 *     require_once MAIN_HOME . 'core/Auth/SessionManager.php';
 *     SessionManager::start('reseller');
 *     SessionManager::requireAuth();
 *
 * @see admin/session.php
 * @see reseller/session.php
 */

class SessionManager {

    /** Session timeout in minutes */
    const DEFAULT_TIMEOUT = 60;

    /** @var string Current context: 'admin' or 'reseller' */
    protected static $context = null;

    /** @var int Timeout in minutes */
    protected static $timeout = self::DEFAULT_TIMEOUT;

    /** @var bool Whether session has been started */
    protected static $started = false;

    /**
     * Session key mapping per context
     *
     * Maps logical names to actual $_SESSION keys for backward compat
     * with existing session data.
     */
    protected static $keyMap = [
        'admin' => [
            'auth'     => 'hash',
            'activity' => 'last_activity',
            'ip'       => 'ip',
            'code'     => 'code',
            'verify'   => 'verify',
        ],
        'reseller' => [
            'auth'     => 'reseller',
            'activity' => 'rlast_activity',
            'ip'       => 'rip',
            'code'     => 'rcode',
            'verify'   => 'rverify',
        ],
    ];

    /**
     * Start a session for the given context
     *
     * @param string $context 'admin' or 'reseller'
     * @param int $timeout Timeout in minutes (default: 60)
     */
    public static function start($context, $timeout = self::DEFAULT_TIMEOUT) {
        self::$context = $context;
        self::$timeout = $timeout;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        self::$started = true;
        self::checkTimeout();
    }

    /**
     * Require authentication — redirect to login if not authenticated
     *
     * If called as an AJAX endpoint (script_filename == session.php),
     * returns JSON result instead of redirecting.
     *
     * @param string|null $loginUrl Override login redirect URL
     */
    public static function requireAuth($loginUrl = null) {
        $authKey = self::getKey('auth');

        // Direct access to session.php endpoint — return JSON status
        if (basename($_SERVER['SCRIPT_FILENAME']) === 'session.php') {
            $isAuth = isset($_SESSION[$authKey]);
            echo json_encode(['result' => $isAuth]);
            exit;
        }

        // Not authenticated — redirect to login
        if (!isset($_SESSION[$authKey])) {
            if ($loginUrl === null) {
                $prefix = (self::$context === 'reseller') ? '' : './';
                $loginUrl = $prefix . 'login?referrer=' . urlencode(basename($_SERVER['REQUEST_URI'], '.php'));
            }

            header('Location: ' . $loginUrl);
            exit;
        }

        // Authenticated — update activity timestamp and close session
        self::touch();
    }

    /**
     * Check if user is authenticated (non-blocking, no redirect)
     *
     * @return bool
     */
    public static function isAuthenticated() {
        if (!self::$started) {
            return false;
        }

        $authKey = self::getKey('auth');
        return isset($_SESSION[$authKey]);
    }

    /**
     * Get the auth token/hash for current session
     *
     * @return string|null
     */
    public static function getUser() {
        $authKey = self::getKey('auth');
        return isset($_SESSION[$authKey]) ? $_SESSION[$authKey] : null;
    }

    /**
     * Get a session value by logical name
     *
     * @param string $name Logical name: 'auth', 'activity', 'ip', 'code', 'verify'
     * @return mixed|null
     */
    public static function getValue($name) {
        $key = self::getKey($name);
        return isset($_SESSION[$key]) ? $_SESSION[$key] : null;
    }

    /**
     * Set a session value by logical name
     *
     * @param string $name Logical name
     * @param mixed $value Value to store
     */
    public static function setValue($name, $value) {
        $key = self::getKey($name);
        $_SESSION[$key] = $value;
    }

    /**
     * Create an authenticated session
     *
     * @param string $hash Authentication hash/token
     * @param string|null $ip Client IP address
     */
    public static function login($hash, $ip = null) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        self::setValue('auth', $hash);
        self::setValue('activity', time());

        if ($ip !== null) {
            self::setValue('ip', $ip);
        }
    }

    /**
     * Destroy the current session (logout)
     */
    public static function destroy() {
        if (!self::$started && session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Clear context-specific keys
        foreach (self::$keyMap[self::$context] as $key) {
            if (isset($_SESSION[$key])) {
                unset($_SESSION[$key]);
            }
        }

        // If no other context is active, destroy the whole session
        $otherContext = (self::$context === 'admin') ? 'reseller' : 'admin';
        $otherAuthKey = self::$keyMap[$otherContext]['auth'];

        if (!isset($_SESSION[$otherAuthKey])) {
            session_destroy();
        }

        self::$started = false;
    }

    /**
     * Update the last activity timestamp and close session for writing
     */
    public static function touch() {
        $activityKey = self::getKey('activity');
        $_SESSION[$activityKey] = time();
        session_write_close();
    }

    /**
     * Get current context
     *
     * @return string|null
     */
    public static function getContext() {
        return self::$context;
    }

    // ───────────────────────────────────────────────────────────
    //  Internal Methods
    // ───────────────────────────────────────────────────────────

    /**
     * Check for session timeout and expire if needed
     */
    protected static function checkTimeout() {
        $authKey = self::getKey('auth');
        $activityKey = self::getKey('activity');

        if (isset($_SESSION[$authKey]) && isset($_SESSION[$activityKey])) {
            $elapsed = time() - $_SESSION[$activityKey];

            if ($elapsed > (self::$timeout * 60)) {
                // Session expired — clear all context-specific keys
                foreach (self::$keyMap[self::$context] as $key) {
                    if (isset($_SESSION[$key])) {
                        unset($_SESSION[$key]);
                    }
                }

                // Restart session if it was destroyed
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
            }
        }
    }

    /**
     * Get the actual $_SESSION key for a logical name in current context
     *
     * @param string $name Logical name: 'auth', 'activity', 'ip', 'code', 'verify'
     * @return string
     */
    protected static function getKey($name) {
        if (self::$context === null) {
            self::$context = 'admin'; // default fallback
        }

        if (isset(self::$keyMap[self::$context][$name])) {
            return self::$keyMap[self::$context][$name];
        }

        // Unknown key — return as-is (allows extending)
        return $name;
    }
}
