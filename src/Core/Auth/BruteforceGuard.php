<?php

namespace XcVm\Core\Auth;

use XcVm\Core\Cluster\BlocklistChanges;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\AtomicFile;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Signal\SignalQueue;

/**
 * Bruteforce / Flood Guard
 *
 * Centralized rate-limiting and brute-force protection.
 * (identical logic, unified here).
 *
 * Every count and block marker is a file named after the address, so the
 * checks count IP addresses only: any other value is left uncounted.
 *
 * A count file is replaced by rename, so a request reads the state another
 * wrote before or after, never half of it; one that still does not read as
 * that state starts its count again.
 *
 * @package XC_VM_Core_Auth
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BruteforceGuard {
	/** The reasons blockIP() records, which MAIN accepts from a node's security.block_ip. */
	public const REASON_PATTERN = '/^(FLOOD|BRUTEFORCE (MAC|USER)) ATTACK$/';

	/**
	 * Resolve panel settings, preferring SettingsManager over the legacy global.
	 *
	 * @return array Settings array, or [] when none are available.
	 */
	private static function getSettings(): array {
		if (!empty(SettingsManager::getAll())) {
			return SettingsManager::getAll();
		}
		if (!empty($GLOBALS['rSettings'])) {
			return $GLOBALS['rSettings'];
		}
		return [];
	}

	/**
	 * Resolve the user's IP address.
	 */
	private static function getUserIP(): string {
		if (class_exists(NetworkUtils::class, false)) {
			return NetworkUtils::getUserIP();
		}
		return $_SERVER['REMOTE_ADDR'] ?? '';
	}

	/**
	 * Get the allowed IPs list.
	 */
	private static function getAllowedIPs(): array {
		if (class_exists(ServerRepository::class, false)) {
			return ServerRepository::getAllowedIPs();
		}
		if (isset($GLOBALS['rAllowedIPs'])) {
			return $GLOBALS['rAllowedIPs'];
		}
		return [];
	}

	/**
	 * Get the blocked IPs list.
	 */
	private static function getBlockedIPs(): array {
		if (class_exists(BlocklistService::class, false)) {
			return BlocklistService::getBlockedIPs();
		}
		if (isset($GLOBALS['rBlockedIPs'])) {
			return $GLOBALS['rBlockedIPs'];
		}
		return [];
	}

	/**
	 * Get database instance.
	 */
	private static function getDB(): ?object {
		if (class_exists(DatabaseFactory::class, false) && DatabaseFactory::get() instanceof \XcVm\Core\Database\DatabaseHandler) {
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
	 * @param bool   $useCachedMode  Use signal-based blocking
	 */
	private static function blockIP(string $ip, string $reason, bool $useCachedMode = false): void {
		if ($useCachedMode && !empty($GLOBALS['rCached'])) {
			$signalKey = (stripos($reason, 'BRUTEFORCE') !== false ? 'bruteforce_attack' : 'flood_attack');
			SignalQueue::push($signalKey . '/' . $ip, 1);
		} elseif (self::spoolBlock($ip, $reason)) {
			// MAIN records it (security.block_ip); the block file below applies it here now.
		} else {
			$db = self::getDB();
			if ($db) {
				$db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $ip, $reason, time());
				BlocklistChanges::set('ip', [$ip], $db);
			}
			// Force-refresh blocked IPs cache
			if (class_exists(BlocklistService::class, false)) {
				BlocklistService::getBlockedIPs(true);
			}
		}
		touch(FLOOD_TMP_PATH . 'block_' . $ip);
	}

	/**
	 * On a load balancer whose CONFIG flow is on (cluster plan, Phase 5), the
	 * blocklist is MAIN's: the block goes to MAIN as a P0 `security.block_ip`
	 * event instead of a write to its database. False when it could not be
	 * spooled (flow off, agent stopped, MAIN itself): the caller writes it.
	 */
	private static function spoolBlock(string $ip, string $reason): bool {
		if (!class_exists(NodeFlows::class) || !NodeFlows::on(NodeFlows::CONFIG)) {
			return false;
		}
		return EventSpool::append('p0', [['type' => 'security.block_ip', 'd' => ['ip' => $ip, 'reason' => $reason]]]);
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
		if (!filter_var($ip, FILTER_VALIDATE_IP) || in_array($ip, $allowedIPs)) {
			return;
		}

		$floodExclude = array_filter(array_unique(explode(',', $settings['flood_ips_exclude'] ?? '')));
		if (in_array($ip, $floodExclude)) {
			return;
		}

		$ipFile = FLOOD_TMP_PATH . $ip;
		$floodRow = (file_exists($ipFile) ? json_decode(file_get_contents($ipFile), true) : null);
		if (is_array($floodRow)) {
			$floodSeconds = $settings['flood_seconds'];
			$floodLimit = $settings['flood_limit'];

			if (time() - $floodRow['last_request'] <= $floodSeconds) {
				$floodRow['requests']++;
				if ($floodLimit > $floodRow['requests']) {
					$floodRow['last_request'] = time();
					AtomicFile::write($ipFile, (string) json_encode($floodRow));
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
				AtomicFile::write($ipFile, (string) json_encode($floodRow));
			}
		} else {
			AtomicFile::write($ipFile, (string) json_encode(['requests' => 0, 'last_request' => time()]));
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

		if ($mac && (empty($settings['bruteforce_mac_attempts']) || $settings['bruteforce_mac_attempts'] == 0)) {
			return;
		}
		if ($username && (empty($settings['bruteforce_username_attempts']) || $settings['bruteforce_username_attempts'] == 0)) {
			return;
		}

		if (!$ip) {
			$ip = self::getUserIP();
		}

		$allowedIPs = self::getAllowedIPs();
		if (!filter_var($ip, FILTER_VALIDATE_IP) || in_array($ip, $allowedIPs)) {
			return;
		}

		$floodExclude = array_filter(array_unique(explode(',', $settings['flood_ips_exclude'] ?? '')));
		if (in_array($ip, $floodExclude)) {
			return;
		}

		$floodType = (!is_null($mac) ? 'mac' : 'user');
		$term = (!is_null($mac) ? $mac : $username);
		// The count is kept as JSON, which holds valid UTF-8 only: any other term is counted by its digest.
		if (json_encode($term) === false) {
			$term = md5($term);
		}
		$ipFile = FLOOD_TMP_PATH . $ip . '_' . $floodType;
		$floodRow = (file_exists($ipFile) ? json_decode(file_get_contents($ipFile), true) : null);

		if (is_array($floodRow)) {
			$floodSeconds = intval($settings['bruteforce_frequency']);
			$floodLimit = intval($settings[['mac' => 'bruteforce_mac_attempts', 'user' => 'bruteforce_username_attempts'][$floodType]]);
			$floodRow['attempts'] = self::truncateAttempts($floodRow['attempts'], $floodSeconds);

			if (!in_array($term, array_keys($floodRow['attempts']))) {
				$floodRow['attempts'][$term] = time();
				if ($floodLimit > count($floodRow['attempts'])) {
					AtomicFile::write($ipFile, (string) json_encode($floodRow));
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
			$floodRow = ['attempts' => [$term => time()]];
			AtomicFile::write($ipFile, (string) json_encode($floodRow));
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
		if (!filter_var($ip, FILTER_VALIDATE_IP) || in_array($ip, $allowedIPs)) {
			return;
		}

		$floodExclude = array_filter(array_unique(explode(',', $settings['flood_ips_exclude'] ?? '')));
		if (in_array($ip, $floodExclude)) {
			return;
		}

		$userFile = FLOOD_TMP_PATH . intval($user['id']) . '_' . $ip;
		$floodRow = (file_exists($userFile) ? json_decode(file_get_contents($userFile), true) : null);
		if (is_array($floodRow)) {
			if (isset($floodRow['block_until']) && time() < $floodRow['block_until']) {
				sleep(intval($settings['auth_flood_sleep']));
			}

			$floodSeconds = intval($settings['auth_flood_seconds']);
			$floodLimit = intval($settings['auth_flood_limit']);
			$floodRow['attempts'] = self::truncateAttempts($floodRow['attempts'], $floodSeconds, true);

			if ($floodLimit <= count($floodRow['attempts'])) {
				$floodRow['block_until'] = time() + intval($settings['auth_flood_seconds']);
			}

			$floodRow['attempts'][] = time();
			AtomicFile::write($userFile, (string) json_encode($floodRow));
		} else {
			AtomicFile::write($userFile, (string) json_encode(['attempts' => [time()]]));
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
		$allowed = [];
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
