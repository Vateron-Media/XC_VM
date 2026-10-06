<?php

namespace XcVm\Core\Util;

/**
 * Public Address
 *
 * Is an IP address one the panel may fetch from when the URL came from a
 * visitor? Only a public address is: loopback, private (RFC 1918, fc00::/7),
 * link-local (169.254/16, which holds the cloud metadata address, fe80::/10),
 * shared address space (100.64/10) and the other reserved ranges are not.
 *
 * Core, no dependencies: usable on MAIN and on a load balancer alike.
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class PublicAddress {
	/** IPv6 prefixes (12 bytes) whose last four bytes are an IPv4 address. */
	private const IPV4_IN_LAST_BYTES = [
		"\0\0\0\0\0\0\0\0\0\0\xff\xff", // ::ffff:0:0/96, IPv4-mapped
		"\0\0\0\0\0\0\0\0\0\0\0\0",     // ::/96, IPv4-compatible
		"\0\x64\xff\x9b\0\0\0\0\0\0\0\0", // 64:ff9b::/96, NAT64
	];

	/**
	 * @param string $rIP An IPv4 or IPv6 address, without brackets.
	 * @return bool True when it is a public address; false for anything else,
	 *              a string that is not an address included.
	 */
	public static function isPublic(string $rIP): bool {
		$rBin = @inet_pton($rIP);
		if ($rBin === false) {
			return false;
		}

		// An IPv6 address that carries an IPv4 one ends at that IPv4 host, and
		// filter_var looks at the IPv6 form only: judge the address inside.
		if (strlen($rBin) === 16) {
			if (in_array(substr($rBin, 0, 12), self::IPV4_IN_LAST_BYTES, true)) {
				$rIP = inet_ntop(substr($rBin, 12));
			} elseif (substr($rBin, 0, 2) === "\x20\x02") {
				$rIP = inet_ntop(substr($rBin, 2, 4)); // 2002::/16, 6to4
			}
		}

		// filter_var returns false for private (RFC1918, fc00::/7) or reserved
		// (loopback, link-local, 0.0.0.0/8, 240/4, …) addresses.
		if (!filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			return false;
		}

		// filter_var misses CGNAT shared space (RFC 6598, 100.64.0.0/10).
		return filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
			|| (ip2long($rIP) & 0xffc00000) !== (ip2long('100.64.0.0') & 0xffc00000);
	}
}
