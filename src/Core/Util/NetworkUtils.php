<?php

namespace XcVm\Core\Util;

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
	 * Whether two IPs match — exactly, or on the same /24 (first three octets)
	 * when $rSubnetMatch is on. Used to compare a stored connection IP against
	 * the current client IP.
	 *
	 * @param bool        $rSubnetMatch Compare only the leading three octets.
	 * @param string|null $rTargetIP    Stored / other IP.
	 * @param string|null $rClientIP    Current client IP.
	 */
	public static function ipMatches(bool $rSubnetMatch, ?string $rTargetIP, ?string $rClientIP): bool {
		if ($rSubnetMatch) {
			return implode(".", array_slice(explode(".", (string) $rTargetIP), 0, -1)) == implode(".", array_slice(explode(".", (string) $rClientIP), 0, -1));
		}
		return $rTargetIP == $rClientIP;
	}

	/**
	 * Check if an IP address is in a CIDR range
	 *
	 * @param string $ip IP address to check
	 * @param string $cidr CIDR notation (e.g., "192.168.1.0/24")
	 * @return bool
	 */
	public static function ipInCIDR(string $ip, string $cidr) {
		if (strpos($cidr, '/') === false) {
			return $ip === $cidr;
		}

		// Compared as packed bytes, so IPv6 works too: ip2long() returns false for
		// it, and false & mask made every IPv6 address match every IPv6 range.
		list($subnet, $bits) = explode('/', $cidr, 2);
		$rIP = @inet_pton($ip);
		$rSubnet = @inet_pton($subnet);

		if ($rIP === false || $rSubnet === false || strlen($rIP) !== strlen($rSubnet)) {
			return false; // not an address, or one is IPv4 and the other IPv6
		}

		$rBits = (int) $bits;
		if ($rBits < 0 || $rBits > strlen($rIP) * 8) {
			return false;
		}

		$rWhole = intdiv($rBits, 8);
		if ($rWhole > 0 && strncmp($rIP, $rSubnet, $rWhole) !== 0) {
			return false;
		}

		$rRest = $rBits % 8;
		if ($rRest === 0) {
			return true;
		}

		$rMask = chr((0xff << (8 - $rRest)) & 0xff);
		return ($rIP[$rWhole] & $rMask) === ($rSubnet[$rWhole] & $rMask);
	}

	/**
	 * Check if an IP is in any of the given CIDR ranges
	 *
	 * @param string $ip IP address
	 * @param array $cidrs Array of CIDR strings
	 * @return bool
	 */
	public static function ipInAnyCIDR(string $ip, array $cidrs) {
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
	public static function isValidIP(string $ip, bool $allowPrivate = true) {
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
	public static function isPrivateIP(string $ip) {
		return !filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * May the node probe this URL with ffprobe?
	 *
	 * `action=probe` takes a URL from MAIN and hands it to ffprobe, which follows
	 * whatever it is given. Without this the endpoint reaches the node's own
	 * loopback services and the cloud metadata address, and non-network protocols
	 * (`file:`, `concat:`) read local files.
	 *
	 * Private LAN ranges stay allowed: parents and proxies are routinely on them.
	 * Refused are loopback, link-local (including 169.254.169.254), unspecified,
	 * multicast and any name that resolves to one of those.
	 *
	 * @param string $rUrl Probe target.
	 * @return bool True when ffprobe may be pointed at it.
	 */
	public static function probeTargetAllowed(string $rUrl): bool {
		$rParts = parse_url(trim($rUrl));
		if ($rParts === false || empty($rParts['host']) || empty($rParts['scheme'])) {
			return false;
		}
		if (!in_array(strtolower($rParts['scheme']), ['http', 'https'], true)) {
			return false;
		}

		$rHost = trim($rParts['host'], '[]');
		$rIPs = filter_var($rHost, FILTER_VALIDATE_IP) !== false ? [$rHost] : self::resolveHost($rHost);
		if ($rIPs === []) {
			return false;
		}

		foreach ($rIPs as $rIP) {
			if (self::isLocalOrLinkLocalIP($rIP)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Every A/AAAA address a name resolves to, or [] when it does not resolve.
	 *
	 * @return list<string>
	 */
	private static function resolveHost(string $rHost): array {
		$rIPs = [];
		foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $rKey => $rType) {
			foreach (@dns_get_record($rHost, $rType) ?: [] as $rRecord) {
				$rIP = $rRecord[$rKey === 'A' ? 'ip' : 'ipv6'] ?? '';
				if ($rIP !== '') {
					$rIPs[] = $rIP;
				}
			}
		}
		if ($rIPs === []) {
			$rResolved = gethostbyname($rHost);
			if ($rResolved !== $rHost) {
				$rIPs[] = $rResolved;
			}
		}

		return $rIPs;
	}

	/**
	 * Loopback, link-local, unspecified or multicast — the addresses a probe
	 * target may never resolve to. Private LAN ranges are not in this set.
	 */
	public static function isLocalOrLinkLocalIP(string $rIP): bool {
		if (filter_var($rIP, FILTER_VALIDATE_IP) === false) {
			return true;
		}
		// ::ffff:127.0.0.1 and friends, however written (::ffff:7f00:1): judge the embedded IPv4.
		$rBin = (string) @inet_pton($rIP);
		if (strlen($rBin) === 16 && substr($rBin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
			$rIP = (string) inet_ntop(substr($rBin, 12));
		}

		$rBlocked = ['127.0.0.0/8', '0.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '::1/128', '::/128', 'fe80::/10', 'ff00::/8'];
		return self::ipInAnyCIDR($rIP, $rBlocked);
	}

	/**
	 * Convert a long integer to IP address
	 *
	 * @return string
	 */
	public static function longToIP(int $long) {
		return long2ip($long);
	}

	/**
	 * Convert an IP address to long integer
	 *
	 * @return int
	 */
	public static function ipToLong(string $ip) {
		return ip2long($ip);
	}

	/**
	 * Возвращает IP-адрес текущего клиента (REMOTE_ADDR).
	 * Empty on the command line, which has no client.
	 *
	 * @return string
	 */
	public static function getUserIP() {
		return $_SERVER['REMOTE_ADDR'] ?? '';
	}

	/**
	 * The slot file this request holds for its download, by download type.
	 *
	 * @var array<string, array{0: string, 1: resource}>
	 */
	private static array $rDownloadSlots = [];

	/**
	 * Admit a download under a user's limit of concurrent downloads of its type.
	 *
	 * A running download is an exclusive lock on one of the user's slot files of
	 * that type, as many as the limit, held by the request that serves it. The
	 * lock ends with the request however it ends, so a download that is over
	 * never holds a slot. Restreamers and a zero limit are always allowed.
	 *
	 * @param string $rType        Download type ('epg' or 'playlist').
	 * @param array  $rUser        User row.
	 * @param int    $rDownloadPID Not used: the download is the lock, not the process.
	 * @param int    $rFloodLimit  Max concurrent downloads (0 = unlimited).
	 * @return bool True if the download is allowed.
	 */
	public static function startDownload(string $rType, array $rUser, int $rDownloadPID, int $rFloodLimit) {
		if ($rFloodLimit != 0) {
			if (!$rUser['is_restreamer']) {
				// A process serves one download of a type at a time.
				self::stopDownload($rType, $rUser, $rDownloadPID, $rFloodLimit);
				for ($rSlot = 0; $rSlot < $rFloodLimit; $rSlot++) {
					$rFile = FLOOD_TMP_PATH . $rUser['id'] . '_downloads_' . $rType . '_' . $rSlot;
					$rHandle = @fopen($rFile, 'c');
					if ($rHandle === false) {
						// No slot can be kept (tmp/ is full or missing): nobody is refused for that.
						return true;
					}
					if (flock($rHandle, LOCK_EX | LOCK_NB, $rBusy)) {
						// cron:tmp removes a file of this directory ten minutes after its last
						// change: dated a day ahead, the slot stays for as long as a download runs.
						@touch($rFile, time() + 86400);
						self::$rDownloadSlots[$rType] = [$rFile, $rHandle];
						return true;
					}
					fclose($rHandle);
					if (!$rBusy) {
						return true;
					}
				}
				return false;
			}
			return true;
		}
		return true;
	}

	/**
	 * Give back the slot of this request's download of the type, if it holds one.
	 *
	 * @param string $rType        Download type ('epg' or 'playlist').
	 * @param array  $rUser        User row.
	 * @param int    $rDownloadPID Not used.
	 * @param int    $rFloodLimit  Not used: a slot taken is given back whatever the limit is now.
	 * @return void
	 */
	public static function stopDownload(string $rType, array $rUser, int $rDownloadPID, int $rFloodLimit) {
		if (!isset(self::$rDownloadSlots[$rType])) {
			return;
		}
		[$rFile, $rHandle] = self::$rDownloadSlots[$rType];
		unset(self::$rDownloadSlots[$rType]);
		// Dated now again: cron:tmp removes the file ten minutes after the slot was last used.
		@touch($rFile);
		// Unlocked, not only closed: a process started during the download may hold a copy of the handle.
		flock($rHandle, LOCK_UN);
		fclose($rHandle);
	}
}
