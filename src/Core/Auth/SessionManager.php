<?php

namespace XcVm\Core\Auth;

use XcVm\Core\Bootstrap\Stage\SessionStage;
use XcVm\Core\Util\NetworkUtils;

/**
 * Unified Session Manager
 *
 * Consolidates admin/reseller/player session-key handling into a single
 * class with role contexts, instead of each scope duplicating its own
 * $_SESSION key names and timeout/redirect logic.
 *
 * Session Keys by Context:
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
 *   Player:
 *     phash    — session authentication hash (line/registered-user id)
 *     pverify  — verification flag (md5 of username||password)
 *
 * Current usage:
 *
 *   start() + requireAuth() are called directly by the $noBootstrapPages
 *   view scripts that skip the full scope bootstrap (Public/Views/admin/
 *   setup.php, post.php, player.php) — see AdminScopeBootstrap's docblock.
 *
 *   clearContext() tears down one context's keys without destroying the
 *   whole session: used by AdminScopeBootstrap/ResellerScopeBootstrap on an
 *   invalid identity, and by the Player/PlayerV2 login+logout controllers.
 *
 *   adminSessionValid() is the shared admin-session integrity check used by
 *   both AdminScopeBootstrap::hydrateAdminContext() and Admin\TableController.
 *
 * @see \XcVm\Infrastructure\Bootstrap\AdminScopeBootstrap
 * @see \XcVm\Infrastructure\Bootstrap\ResellerScopeBootstrap
 * @see \XcVm\Infrastructure\Bootstrap\PlayerScopeBootstrap
 *
 * @package XC_VM_Core_Auth
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SessionManager {
	public const DEFAULT_TIMEOUT = 60;

	protected static ?string $context = null;

	protected static int $timeout = self::DEFAULT_TIMEOUT;

	protected static bool $started = false;

	/** @var array<string, array<string, string>> */
	protected static array $keyMap = [
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
		'player' => [
			'auth'     => 'phash',
			'verify'   => 'pverify',
		],
	];

	/**
	 * Start a session for the given context. post.php, which nginx runs itself
	 * for a form save, makes the request's first start here, so the session is
	 * started the way the session stage starts it.
	 *
	 * @param string $context 'admin' or 'reseller'
	 * @param int $timeout Timeout in minutes (default: 60)
	 */
	public static function start(string $context, int $timeout = self::DEFAULT_TIMEOUT): void {
		self::$context = $context;
		self::$timeout = $timeout;

		if (session_status() === PHP_SESSION_NONE) {
			SessionStage::startSession();
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
	public static function requireAuth(?string $loginUrl = null): void {
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
	 */
	public static function isAuthenticated(): bool {
		if (!self::$started) {
			return false;
		}

		$authKey = self::getKey('auth');
		return isset($_SESSION[$authKey]);
	}

	/**
	 * Get the auth token/hash for current session
	 */
	public static function getUser(): mixed {
		$authKey = self::getKey('auth');
		return isset($_SESSION[$authKey]) ? $_SESSION[$authKey] : null;
	}

	/**
	 * Get a session value by logical name
	 *
	 * @param string $name Logical name: 'auth', 'activity', 'ip', 'code', 'verify'
	 */
	public static function getValue(string $name): mixed {
		$key = self::getKey($name);
		return isset($_SESSION[$key]) ? $_SESSION[$key] : null;
	}

	/**
	 * Set a session value by logical name
	 *
	 * @param string $name Logical name
	 * @param mixed $value Value to store
	 */
	public static function setValue(string $name, mixed $value): void {
		$key = self::getKey($name);
		$_SESSION[$key] = $value;
	}

	/**
	 * Create an authenticated session
	 *
	 * @param mixed $hash Authentication hash/token
	 * @param string|null $ip Client IP address
	 */
	public static function login(mixed $hash, ?string $ip = null): void {
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
	public static function destroy(): void {
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
	 * Clear session keys for a specific context without destroying the session.
	 * Drop-in replacement for legacy destroySession($type).
	 *
	 * @param string $context 'admin', 'reseller', or 'player'
	 */
	public static function clearContext(string $context): void {
		if (!isset(self::$keyMap[$context])) {
			return;
		}
		foreach (self::$keyMap[$context] as $key) {
			unset($_SESSION[$key]);
		}
	}

	/**
	 * Integrity check for an authenticated admin session. The single definition
	 * shared by the HTML bootstrap (AdminScopeBootstrap::hydrateAdminContext) and
	 * the JSON table endpoint (Admin\TableController). Returns true only when the
	 * user and permissions exist, the account is an admin and still enabled, the
	 * login IP still matches (when ip_logout is enabled), and the stored verify
	 * hash matches the user's current credentials. Callers act on `false`
	 * themselves (redirect for HTML, JSON error for AJAX) after
	 * SessionManager::clearContext('admin').
	 *
	 * @param array|null $rUserInfo    Registered-user row, or null when not found.
	 * @param array|null $rPermissions Resolved permissions, or null.
	 * @param array      $rSettings    Settings row (ip_subnet_match, ip_logout).
	 */
	public static function adminSessionValid(?array $rUserInfo, ?array $rPermissions, array $rSettings): bool {
		if (!self::adminIdentityValid($rUserInfo, $rPermissions)) {
			return false;
		}

		if (!self::adminIpAllowed($rSettings)) {
			return false;
		}

		return $_SESSION['verify'] == md5($rUserInfo['username'] . '||' . $rUserInfo['password']);
	}

	/**
	 * Identity side of the admin session guard: a user row and permissions exist,
	 * the account is flagged as an admin and it is still enabled. A login needs
	 * status 1 (Authenticator), and so does the session it opened: disabling an
	 * account ends it.
	 *
	 * @param array|null $rUserInfo    Registered-user row, or null when not found.
	 * @param array|null $rPermissions Resolved permissions, or null.
	 */
	private static function adminIdentityValid(?array $rUserInfo, ?array $rPermissions): bool {
		if (!$rUserInfo || !$rPermissions || empty($rPermissions['is_admin'])) {
			return false;
		}

		return $rUserInfo['status'] == 1;
	}

	/**
	 * IP side of the admin session guard: allowed unless ip_logout is enabled and
	 * the request IP no longer matches the login IP — the whole IP, or just the
	 * subnet when ip_subnet_match is set.
	 *
	 * @param array $rSettings Settings row (ip_logout, ip_subnet_match).
	 */
	private static function adminIpAllowed(array $rSettings): bool {
		if (!$rSettings['ip_logout']) {
			return true;
		}

		$rIP = NetworkUtils::getUserIP();
		if ($rSettings['ip_subnet_match']) {
			return NetworkUtils::ipMatches(true, (string) $_SESSION['ip'], (string) $rIP);
		}

		return $_SESSION['ip'] == $rIP;
	}

	/**
	 * Update the last activity timestamp and close session for writing
	 */
	public static function touch(): void {
		$activityKey = self::getKey('activity');
		$_SESSION[$activityKey] = time();
		session_write_close();
	}

	/**
	 * Get current context
	 */
	public static function getContext(): ?string {
		return self::$context;
	}

	// ───────────────────────────────────────────────────────────
	//  Internal Methods
	// ───────────────────────────────────────────────────────────

	/**
	 * Check for session timeout and expire if needed
	 */
	protected static function checkTimeout(): void {
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
	 */
	protected static function getKey(string $name): string {
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
